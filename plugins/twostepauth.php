<?php
/**
 * JARIZ.PRO
 * Date: 25/07/13
 * Time: 21:34
 * Author: JariZ
 */

// Disallow direct access to this file for security reasons
if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

//Login/register shit
$plugins->add_hook("member_do_login_start", "twostepauth_login");
$plugins->add_hook("member_do_register_end", "twostepauth_register");

//UserCP shit
$plugins->add_hook("usercp_start", "twostepauth_usercp_start");
$plugins->add_hook("usercp_menu", "twostepauth_usercp_menu");


function twostepauth_info()
{
    return array(
        "name" => "2StepAuth",
        "description" => "A plugin that provides basic 2 step authentication trough google authenticator.",
        "website" => "http://jariz.pro",
        "author" => "Jari Zwarts",
        "authorsite" => "http://jariz.pro",
        "version" => "1.0",
        "guid" => "",
        "compatibility" => "*"
    );
}

/**
 * TWOSTEPAUTH DB STRUCTURE:
 *
 * twostepauth_authorizations : id, ip, code, uid
 *      id: record id
 *      ip: ip user to authorize
 *      code: google authenticator code (not secret!)
 *      uid: user id
 *
 * users : twostepauth_secret, twostepauth_enabled
 *      twostepauth_secret: the generated secret (which gets generated on plugin activation for all users & on registration)
 *      twostepauth_enabled: does the user have 2stepauth enabled?
 */


/**
 * INSTALL SHIT
 */

function twostepauth_install()
{
    global $db, $config, $page, $mybb;

    if(!isset($config["2stepauth_secret_encryption_key"])) {
        //ok, the encryption key does not exist. can we write it into the config?
        if(!is_writable(MYBB_ROOT."inc/config.php")) {
            //show epic custom error page
            twostepauth_admin_error("inc/config.php is not writable.", "<strong>In order to install 2stepauth, this file must be writeable.</strong><br>The installation was aborted.");
        } else {
            //attempt to generate & add our sup0r secret key to the config
            $key = random_str(40);
            $cfile = fopen(MYBB_ROOT."inc/config.php", "at");
            if(fwrite($cfile, "\n<?\n/**\n * The encryption key used to encrypt all 2stepauth secret tokens. If you change this, all your users will be unable to use 2 step authorization.\n */\n\n\$config[\"2stepauth_secret_encryption_key\"] = \"{$key}\";\n?>") === FALSE)
                twostepauth_admin_error("Unable to write to inc/config.php", "2StepAuth was unable to write it's encryption key to your config file.<br>Your config file might be damaged.<br>The installation was aborted.");
            fclose($cfile);
            //set key temporarily for the rest of the installation
            $mybb->config["2stepauth_secret_encryption_key"] = $key;
        }
    }

    $cipher = twostepauth_set_up_rijndael();

    if (!$db->table_exists("twostepauth_authorizations"))
        $db->query("CREATE TABLE `" . TABLE_PREFIX . "twostepauth_authorizations` (
`id`  int NULL AUTO_INCREMENT ,
`ip`  varchar(255) NOT NULL ,
`location` TEXT NOT NULL ,
`code`  int(6) NOT NULL ,
`uid`  int NOT NULL ,
PRIMARY KEY (`id`)
);");

    if (!$db->field_exists("twostepauth_secret", "users"))
        $db->query("ALTER TABLE " . TABLE_PREFIX . "users ADD `twostepauth_secret` VARCHAR(16) NOT NULL default ''");

    if (!$db->field_exists("twostepauth_enabled", "users"))
        $db->query("ALTER TABLE " . TABLE_PREFIX . "users ADD `twostepauth_enabled` INT(1) NOT NULL default '0'");

    //if(!)

    //give secrets to users that don't have them yet
    $auth = new PHPGangsta_GoogleAuthenticator();
    $empties = $db->simple_select("users", "uid", "twostepauth_secret = ''");
    while ($empty = $db->fetch_array($empties)) {
        $db->update_query("users", array("twostepauth_secret" => $auth->createSecret()), "uid = " . $empty["uid"]);
    }

    // Insert settings in to the database
    $query = $db->query("SELECT disporder FROM " . TABLE_PREFIX . "settinggroups ORDER BY `disporder` DESC LIMIT 1");
    $disporder = $db->fetch_field($query, 'disporder') + 1;

    $setting_group = array(
        'name' => 'twostepauth_settings',
        'title' => '2StepAuth',
        'description' => 'Settings to customize the 2StepAuth system.',
        'disporder' => intval($disporder),
        'isdefault' => 0
    );
    $db->insert_query('settinggroups', $setting_group);
    $gid = $db->insert_id();

    $settings = array(
        'force' => array(
            'title' => 'Force users to use 2StepAuth',
            'description' => 'Normally users can choose if they want to use 2StepAuth, this option forces users to use the system, and also removes the choice if enabling/disabling 2StepAuth',
            'optionscode' => 'onoff',
            'value' => '0')
    );

    $x = 1;
    foreach ($settings as $name => $setting) {
        $insert_settings = array(
            'name' => $db->escape_string("twostepauth_" . $name),
            'title' => $db->escape_string($setting['title']),
            'description' => $db->escape_string($setting['description']),
            'optionscode' => $db->escape_string($setting['optionscode']),
            'value' => $db->escape_string($setting['value']),
            'disporder' => $x,
            'gid' => $gid,
            'isdefault' => 0
        );
        $db->insert_query('settings', $insert_settings);
        $x++;
    }
}

function twostepauth_admin_error($title, $msg) {
    global $page;
    $page->output_header("2StepAuth Error");
    $page->add_breadcrumb_item("2StepAuth Error", "index.php?module=config-plugins");
    $page->output_error("<p><em>{$title}</em></p><p>{$msg}</p>");
    $page->output_footer();
    exit;
}

function twostepauth_activate()
{
    global $db;
//    require MYBB_ROOT . '/inc/adminfunctions_templates.php';
//    find_replace_templatesets(
//        "index",
//        '#' . preg_quote('{$boardstats}') . '#',
//        '{$boardstats}{$randomvar}'
//    );


    $info = twostepauth_info();

    $usercp_twostepauth = <<<USERCP
<html>
<head>
    <title>{\$mybb->settings['bbname']} - {\$lang->setup_2stepauth}</title>
    {\$headerinclude}
</head>
<body>
{\$header}
<form action="usercp.php" method="post">
    <input type="hidden" name="my_post_key" value="{\$mybb->post_code}"/>
    <table width="100%" border="0" align="center">
        <tr>
            {\$usercpnav}
            <td valign="top">
                {\$errors}
                <table border="0" cellspacing="{\$theme['borderwidth']}" cellpadding="{\$theme['tablespace']}"
                       class="tborder">
                    <tr>
                        <td class="thead" colspan="2"><strong>{\$lang->setup_2stepauth}</strong></td>
                    </tr>
                    <tr>
                        <td width="50%" class="trow1" valign="top">
                            <fieldset class="trow2">
                                <legend><strong>{\$lang->twostepauth_enable}</strong></legend>
                                <table cellspacing="0" cellpadding="2">
                                    <tr>
                                        <td valign="top" width="1">
                                            <input type="checkbox" class="checkbox" id="twostepauth_enable" name="twostepauth_enable" value="1"/>
                                        </td>
                                        <td><span class="smalltext"><label for="twostepauth_enable">{\$lang->twostepauth_enable}</label></span></td>
                                    </tr>
                                </table>
                            </fieldset>

                            <fieldset class="trow2">
                                <legend><strong>{\$lang->twostepauth_qr}</strong></legend>
                                <table cellspacing="0" cellpadding="2">
                                    <tr>
                                        <td>
                                            <img src="\$qr"/>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><p>{\$lang->twostepauth_qr_explanation}</p></td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2"><img src="http://i.imgur.com/ppw1gDn.png"></a>
                                            <a href="https://itunes.apple.com/en/app/google-authenticator/id388497605"><img src="http://i.imgur.com/p71hGce.png"></a>
                                        </td>
                                    </tr>
                                </table>
                            </fieldset>

                        </td>
                        <td width="50%" class="trow1" valign="top">
                            <fieldset class="trow2">
                                <legend><strong>{\$lang->twostepauth_authorizations}</strong></legend>
                                <table border="0" cellspacing="1" cellpadding="4" class="tborder">
                                    <tr>
                                        <td class="tcat">test</td>
                                        <td class="tcat">testtt</td>
                                        <td class="tcat">tsdewdawd</td>
                                    </tr>
                                </table>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <br/>

                <div align="center">
                    <input type="hidden" name="action" value="do_options"/>
                    <input type="submit" class="button" name="regsubmit" value="{\$lang->update_options}"/>
                </div>
            </td>
        </tr>
    </table>
</form>
{\$footer}
</body>
</html>
USERCP;

    $templates = array(
        "usercp_twostepauth" => $usercp_twostepauth
    );

    foreach ($templates as $template_title => $template_data) {
        $insert_templates = array(
            'title' => $db->escape_string($template_title),
            'template' => $db->escape_string($template_data),
            'sid' => "-1",
            'version' => $info['intver'],
            'dateline' => TIME_NOW
        );
        $db->insert_query('templates', $insert_templates);
    }
}

function twostepauth_uninstall()
{
    global $db;

    // Remove settings
    $result = $db->simple_select('settinggroups', 'gid', "name = 'twostepauth_settings'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if (!empty($group['gid'])) {
        $db->delete_query('settinggroups', "gid='{$group['gid']}'");
        $db->delete_query('settings', "gid='{$group['gid']}'");
        rebuild_settings();
    }

    // This part will remove the database tables
    // To avoid 'accidentally' uninstalling and loosing all your authorizations, this part only runs if there is a blank twostepauth_unlock file
    // This completely uninstall including removing all the authenticated ips & secrets from the database.
    if (file_exists(MYBB_ROOT . "twostepauth_unlock")) {

        if ($db->field_exists('twostepauth_enabled', 'users'))
            $db->query("ALTER TABLE " . TABLE_PREFIX . "users DROP column `twostepauth_enabled`");

        if ($db->field_exists('twostepauth_secret', 'users'))
            $db->query("ALTER TABLE " . TABLE_PREFIX . "users DROP column `twostepauth_secret`");

        if ($db->table_exists("twostepauth_authorizations"))
            $db->drop_table("twostepauth_authorizations");
    }
}

function twostepauth_deactivate()
{
    global $db;
    $db->delete_query("templates", "title='usercp_twostepauth'");
}

function twostepauth_is_installed()
{
    global $db;
    /*if ($db->table_exists("twostepauth_authorizations") && $db->field_exists("twostepauth_secret", "users") && $db->field_exists("twostepauth_enabled", "users")) {
        return true;
    }*/
    $result = $db->simple_select('settinggroups', 'gid', "name = 'twostepauth_settings'", array('limit' => 1));
    $group = $db->fetch_array($result);

    if (!empty($group['gid'])) return true;

    return false;
}

/**
 * SECRET/VERIFCATION SHIT
 */

function twostepauth_register()
{
    global $user_info, $db, $mybb;
    $google_auth = new PHPGangsta_GoogleAuthenticator();
    $db->update_query("users", array("twostepauth_secret" => $google_auth->createSecret(), "twostepauth_enabled" => $mybb->settings["twostepauth_force"]), "uid = '{$user_info['uid']}'");
}


function twostepauth_login()
{
    global $user_info, $db, $mybb;
    //error("you r fagot");
}

/**
 * USERCP SHIT
 */

function twostepauth_usercp_menu()
{
    global $lang, $templates;
    $lang->load("twostepauth");
    $template = "\n\t<tr><td class=\"trow1 smalltext\"><a href=\"usercp.php?action=2stepauth\" class=\"usercp_nav_item\" style=\"background:url('images/lockfolder.gif') no-repeat left center\">{$lang->nav_usercp_2stepauth}</a></td></tr>";
    $templates->cache["usercp_nav_misc"] = str_replace("<tbody style=\"{\$collapsed['usercpmisc_e']}\" id=\"usercpmisc_e\">", "<tbody style=\"{\$collapsed['usercpmisc_e']}\" id=\"usercpmisc_e\">{$template}", $templates->cache["usercp_nav_misc"]);
}

function twostepauth_usercp_start()
{
    global $db, $footer, $header, $navigation, $headerinclude, $themes, $mybb, $templates, $usercpnav, $lang;
    $lang->load("twostepauth");
    $auth = new PHPGangsta_GoogleAuthenticator();

    if ($mybb->input['action'] != "2stepauth") {
        return false;
    }

    $qr = $auth->getQRCodeGoogleUrl($mybb->user["username"] . $lang->twostepauth_on . $mybb->settings["bbname"], $mybb->user["twostepauth_secret"]);
    eval("
    \$output = \"" . $templates->get("usercp_twostepauth") . "\";");
    output_page($output);
}

function twostepauth_set_up_rijndael()
{
    global $config;
    $cipher = new Crypt_Rijndael(CRYPT_RIJNDAEL_MODE_ECB);
    $cipher->setKey($config["2stepauth_secret_encryption_key"]);
    return $cipher;
}


/**
 * PHP Class for handling Google Authenticator 2-factor authentication
 *
 * @author Michael Kliewe
 * @copyright 2012 Michael Kliewe
 * @license 1 BSD License
 * @link http://www.phpgangsta.de/
 */
class PHPGangsta_GoogleAuthenticator{protected $a=6;public function createSecret($b=16){$c=$this->_getBase32LookupTable();unset($c[32]);$d='';for($e=0;$e<$b;$e++){$d.=$c[array_rand($c)];}return $d;}public function getCode($d,$f=null){if($f===null){$f=floor(time()/30);}$g=$this->_base32Decode($d);$h=chr(0).chr(0).chr(0).chr(0).pack('N*',$f);$k=hash_hmac('SHA1',$h,$g,true);$l=ord(substr($k,-1))&0x0F;$m=substr($k,$l,4);$n=unpack('N',$m);$n=$n[1];$n=$n&0x7FFFFFFF;$o=pow(10,$this->_codeLength);return str_pad($n%$o,$this->_codeLength,'0',STR_PAD_LEFT);}public function getQRCodeGoogleUrl($p,$d){$q=urlencode('otpauth://totp/'.$p.'?secret='.$d.'');return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl='.$q.'';}public function verifyCode($d,$r,$s=1){$t=floor(time()/30);for($e=-$s;$e<=$s;$e++){$u=$this->getCode($d,$t+$e);if($u==$r){return true;}}return false;}public function setCodeLength($v){$this->_codeLength=$v;return $this;}protected function _base32Decode($d){if(empty($d))return'';$w=$this->_getBase32LookupTable();$aa=array_flip($w);$bb=substr_count($d,$w[32]);$cc=array(6,4,3,1,0);if(!in_array($bb,$cc))return false;for($e=0;$e<4;$e++){if($bb==$cc[$e]&&substr($d,-($cc[$e]))!=str_repeat($w[32],$cc[$e]))return false;}$d=str_replace('=','',$d);$d=str_split($d);$dd="";for($e=0;$e<count($d);$e=$e+8){$ee="";if(!in_array($d[$e],$w))return false;for($ff=0;$ff<8;$ff++){$ee.=str_pad(base_convert(@$aa[@$d[$e+$ff]],10,2),5,'0',STR_PAD_LEFT);}$gg=str_split($ee,8);for($hh=0;$hh<count($gg);$hh++){$dd.=(($ii=chr(base_convert($gg[$hh],2,10)))||ord($ii)==48)?$ii:"";}}return $dd;}protected function _base32Encode($d,$jj=true){if(empty($d))return'';$w=$this->_getBase32LookupTable();$d=str_split($d);$dd="";for($e=0;$e<count($d);$e++){$dd.=str_pad(base_convert(ord($d[$e]),10,2),8,'0',STR_PAD_LEFT);}$kk=str_split($dd,5);$ll="";$e=0;while($e<count($kk)){$ll.=$w[base_convert(str_pad($kk[$e],5,'0'),2,10)];$e++;}if($jj&&($ee=strlen($dd)%40)!=0){if($ee==8)$ll.=str_repeat($w[32],6);elseif($ee==16)$ll.=str_repeat($w[32],4);elseif($ee==24)$ll.=str_repeat($w[32],3);elseif($ee==32)$ll.=$w[32];}return $ll;}protected function _getBase32LookupTable(){return array('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','2','3','4','5','6','7','=');}}

/**
 * LICENSE: This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston,
 * MA  02111-1307  USA
 *
 * @category   Crypt
 * @package    Crypt_Rijndael
 * @author     Jim Wigginton <terrafrost@php.net>
 * @copyright  MMVIII Jim Wigginton
 * @license    http://www.gnu.org/licenses/lgpl.txt
 * @version    $Id: Rijndael.php,v 1.15 2010/09/26 05:02:10 terrafrost Exp $
 * @link       http://phpseclib.sourceforge.net
 */
define('CRYPT_RIJNDAEL_MODE_CTR',-1);define('CRYPT_RIJNDAEL_MODE_ECB',1);define('CRYPT_RIJNDAEL_MODE_CBC',2);define('CRYPT_RIJNDAEL_MODE_CFB',3);define('CRYPT_RIJNDAEL_MODE_OFB',4);define('CRYPT_RIJNDAEL_MODE_INTERNAL',1);define('CRYPT_RIJNDAEL_MODE_MCRYPT',2);
class Crypt_Rijndael{var $a;var $b="\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";var $d='';var $e='';var $f='';var $g=false;var $h=true;var $m=true;var $n=false;var $o;var $p;var $q=16;var $r=4;var $s=16;var $t=4;var $u;var $v;var $x;var $y;var $z;var $aa;var $bb;var $cc;var $dd;var $ee;var $ff=false;var $gg=array('encrypted'=>'','xor'=>'');var $hh=array('ciphertext'=>'');function Crypt_Rijndael($a=CRYPT_RIJNDAEL_MODE_CBC){switch($a){case  CRYPT_RIJNDAEL_MODE_ECB:case  CRYPT_RIJNDAEL_MODE_CBC:$this->paddable=true;$this->mode=$a;break;case  CRYPT_RIJNDAEL_MODE_CTR:case  CRYPT_RIJNDAEL_MODE_CFB:case  CRYPT_RIJNDAEL_MODE_OFB:$this->mode=$a;break;default:$this->paddable=true;$this->mode=CRYPT_RIJNDAEL_MODE_CBC;}$aa=&$this->t3;$z=&$this->t2;$y=&$this->t1;$x=&$this->t0;$ee=&$this->dt3;$dd=&$this->dt2;$cc=&$this->dt1;$bb=&$this->dt0;$aa=array(0x6363A5C6,0x7C7C84F8,0x777799EE,0x7B7B8DF6,0xF2F20DFF,0x6B6BBDD6,0x6F6FB1DE,0xC5C55491,0x30305060,0x01010302,0x6767A9CE,0x2B2B7D56,0xFEFE19E7,0xD7D762B5,0xABABE64D,0x76769AEC,0xCACA458F,0x82829D1F,0xC9C94089,0x7D7D87FA,0xFAFA15EF,0x5959EBB2,0x4747C98E,0xF0F00BFB,0xADADEC41,0xD4D467B3,0xA2A2FD5F,0xAFAFEA45,0x9C9CBF23,0xA4A4F753,0x727296E4,0xC0C05B9B,0xB7B7C275,0xFDFD1CE1,0x9393AE3D,0x26266A4C,0x36365A6C,0x3F3F417E,0xF7F702F5,0xCCCC4F83,0x34345C68,0xA5A5F451,0xE5E534D1,0xF1F108F9,0x717193E2,0xD8D873AB,0x31315362,0x15153F2A,0x04040C08,0xC7C75295,0x23236546,0xC3C35E9D,0x18182830,0x9696A137,0x05050F0A,0x9A9AB52F,0x0707090E,0x12123624,0x80809B1B,0xE2E23DDF,0xEBEB26CD,0x2727694E,0xB2B2CD7F,0x75759FEA,0x09091B12,0x83839E1D,0x2C2C7458,0x1A1A2E34,0x1B1B2D36,0x6E6EB2DC,0x5A5AEEB4,0xA0A0FB5B,0x5252F6A4,0x3B3B4D76,0xD6D661B7,0xB3B3CE7D,0x29297B52,0xE3E33EDD,0x2F2F715E,0x84849713,0x5353F5A6,0xD1D168B9,0x00000000,0xEDED2CC1,0x20206040,0xFCFC1FE3,0xB1B1C879,0x5B5BEDB6,0x6A6ABED4,0xCBCB468D,0xBEBED967,0x39394B72,0x4A4ADE94,0x4C4CD498,0x5858E8B0,0xCFCF4A85,0xD0D06BBB,0xEFEF2AC5,0xAAAAE54F,0xFBFB16ED,0x4343C586,0x4D4DD79A,0x33335566,0x85859411,0x4545CF8A,0xF9F910E9,0x02020604,0x7F7F81FE,0x5050F0A0,0x3C3C4478,0x9F9FBA25,0xA8A8E34B,0x5151F3A2,0xA3A3FE5D,0x4040C080,0x8F8F8A05,0x9292AD3F,0x9D9DBC21,0x38384870,0xF5F504F1,0xBCBCDF63,0xB6B6C177,0xDADA75AF,0x21216342,0x10103020,0xFFFF1AE5,0xF3F30EFD,0xD2D26DBF,0xCDCD4C81,0x0C0C1418,0x13133526,0xECEC2FC3,0x5F5FE1BE,0x9797A235,0x4444CC88,0x1717392E,0xC4C45793,0xA7A7F255,0x7E7E82FC,0x3D3D477A,0x6464ACC8,0x5D5DE7BA,0x19192B32,0x737395E6,0x6060A0C0,0x81819819,0x4F4FD19E,0xDCDC7FA3,0x22226644,0x2A2A7E54,0x9090AB3B,0x8888830B,0x4646CA8C,0xEEEE29C7,0xB8B8D36B,0x14143C28,0xDEDE79A7,0x5E5EE2BC,0x0B0B1D16,0xDBDB76AD,0xE0E03BDB,0x32325664,0x3A3A4E74,0x0A0A1E14,0x4949DB92,0x06060A0C,0x24246C48,0x5C5CE4B8,0xC2C25D9F,0xD3D36EBD,0xACACEF43,0x6262A6C4,0x9191A839,0x9595A431,0xE4E437D3,0x79798BF2,0xE7E732D5,0xC8C8438B,0x3737596E,0x6D6DB7DA,0x8D8D8C01,0xD5D564B1,0x4E4ED29C,0xA9A9E049,0x6C6CB4D8,0x5656FAAC,0xF4F407F3,0xEAEA25CF,0x6565AFCA,0x7A7A8EF4,0xAEAEE947,0x08081810,0xBABAD56F,0x787888F0,0x25256F4A,0x2E2E725C,0x1C1C2438,0xA6A6F157,0xB4B4C773,0xC6C65197,0xE8E823CB,0xDDDD7CA1,0x74749CE8,0x1F1F213E,0x4B4BDD96,0xBDBDDC61,0x8B8B860D,0x8A8A850F,0x707090E0,0x3E3E427C,0xB5B5C471,0x6666AACC,0x4848D890,0x03030506,0xF6F601F7,0x0E0E121C,0x6161A3C2,0x35355F6A,0x5757F9AE,0xB9B9D069,0x86869117,0xC1C15899,0x1D1D273A,0x9E9EB927,0xE1E138D9,0xF8F813EB,0x9898B32B,0x11113322,0x6969BBD2,0xD9D970A9,0x8E8E8907,0x9494A733,0x9B9BB62D,0x1E1E223C,0x87879215,0xE9E920C9,0xCECE4987,0x5555FFAA,0x28287850,0xDFDF7AA5,0x8C8C8F03,0xA1A1F859,0x89898009,0x0D0D171A,0xBFBFDA65,0xE6E631D7,0x4242C684,0x6868B8D0,0x4141C382,0x9999B029,0x2D2D775A,0x0F0F111E,0xB0B0CB7B,0x5454FCA8,0xBBBBD66D,0x16163A2C);$ee=array(0xF4A75051,0x4165537E,0x17A4C31A,0x275E963A,0xAB6BCB3B,0x9D45F11F,0xFA58ABAC,0xE303934B,0x30FA5520,0x766DF6AD,0xCC769188,0x024C25F5,0xE5D7FC4F,0x2ACBD7C5,0x35448026,0x62A38FB5,0xB15A49DE,0xBA1B6725,0xEA0E9845,0xFEC0E15D,0x2F7502C3,0x4CF01281,0x4697A38D,0xD3F9C66B,0x8F5FE703,0x929C9515,0x6D7AEBBF,0x5259DA95,0xBE832DD4,0x7421D358,0xE0692949,0xC9C8448E,0xC2896A75,0x8E7978F4,0x583E6B99,0xB971DD27,0xE14FB6BE,0x88AD17F0,0x20AC66C9,0xCE3AB47D,0xDF4A1863,0x1A3182E5,0x51336097,0x537F4562,0x6477E0B1,0x6BAE84BB,0x81A01CFE,0x082B94F9,0x48685870,0x45FD198F,0xDE6C8794,0x7BF8B752,0x73D323AB,0x4B02E272,0x1F8F57E3,0x55AB2A66,0xEB2807B2,0xB5C2032F,0xC57B9A86,0x3708A5D3,0x2887F230,0xBFA5B223,0x036ABA02,0x16825CED,0xCF1C2B8A,0x79B492A7,0x07F2F0F3,0x69E2A14E,0xDAF4CD65,0x05BED506,0x34621FD1,0xA6FE8AC4,0x2E539D34,0xF355A0A2,0x8AE13205,0xF6EB75A4,0x83EC390B,0x60EFAA40,0x719F065E,0x6E1051BD,0x218AF93E,0xDD063D96,0x3E05AEDD,0xE6BD464D,0x548DB591,0xC45D0571,0x06D46F04,0x5015FF60,0x98FB2419,0xBDE997D6,0x4043CC89,0xD99E7767,0xE842BDB0,0x898B8807,0x195B38E7,0xC8EEDB79,0x7C0A47A1,0x420FE97C,0x841EC9F8,0x00000000,0x80868309,0x2BED4832,0x1170AC1E,0x5A724E6C,0x0EFFFBFD,0x8538560F,0xAED51E3D,0x2D392736,0x0FD9640A,0x5CA62168,0x5B54D19B,0x362E3A24,0x0A67B10C,0x57E70F93,0xEE96D2B4,0x9B919E1B,0xC0C54F80,0xDC20A261,0x774B695A,0x121A161C,0x93BA0AE2,0xA02AE5C0,0x22E0433C,0x1B171D12,0x090D0B0E,0x8BC7ADF2,0xB6A8B92D,0x1EA9C814,0xF1198557,0x75074CAF,0x99DDBBEE,0x7F60FDA3,0x01269FF7,0x72F5BC5C,0x663BC544,0xFB7E345B,0x4329768B,0x23C6DCCB,0xEDFC68B6,0xE4F163B8,0x31DCCAD7,0x63851042,0x97224013,0xC6112084,0x4A247D85,0xBB3DF8D2,0xF93211AE,0x29A16DC7,0x9E2F4B1D,0xB230F3DC,0x8652EC0D,0xC1E3D077,0xB3166C2B,0x70B999A9,0x9448FA11,0xE9642247,0xFC8CC4A8,0xF03F1AA0,0x7D2CD856,0x3390EF22,0x494EC787,0x38D1C1D9,0xCAA2FE8C,0xD40B3698,0xF581CFA6,0x7ADE28A5,0xB78E26DA,0xADBFA43F,0x3A9DE42C,0x78920D50,0x5FCC9B6A,0x7E466254,0x8D13C2F6,0xD8B8E890,0x39F75E2E,0xC3AFF582,0x5D80BE9F,0xD0937C69,0xD52DA96F,0x2512B3CF,0xAC993BC8,0x187DA710,0x9C636EE8,0x3BBB7BDB,0x267809CD,0x5918F46E,0x9AB701EC,0x4F9AA883,0x956E65E6,0xFFE67EAA,0xBCCF0821,0x15E8E6EF,0xE79BD9BA,0x6F36CE4A,0x9F09D4EA,0xB07CD629,0xA4B2AF31,0x3F23312A,0xA59430C6,0xA266C035,0x4EBC3774,0x82CAA6FC,0x90D0B0E0,0xA7D81533,0x04984AF1,0xECDAF741,0xCD500E7F,0x91F62F17,0x4DD68D76,0xEFB04D43,0xAA4D54CC,0x9604DFE4,0xD1B5E39E,0x6A881B4C,0x2C1FB8C1,0x65517F46,0x5EEA049D,0x8C355D01,0x877473FA,0x0B412EFB,0x671D5AB3,0xDBD25292,0x105633E9,0xD647136D,0xD7618C9A,0xA10C7A37,0xF8148E59,0x133C89EB,0xA927EECE,0x61C935B7,0x1CE5EDE1,0x47B13C7A,0xD2DF599C,0xF2733F55,0x14CE7918,0xC737BF73,0xF7CDEA53,0xFDAA5B5F,0x3D6F14DF,0x44DB8678,0xAFF381CA,0x68C43EB9,0x24342C38,0xA3405FC2,0x1DC37216,0xE2250CBC,0x3C498B28,0x0D9541FF,0xA8017139,0x0CB3DE08,0xB4E49CD8,0x56C19064,0xCB84617B,0x32B670D5,0x6C5C7448,0xB85742D0);for($ii=0;$ii<256;$ii++){$z[$ii<<8]=(($aa[$ii]<<8)&0xFFFFFF00)|(($aa[$ii]>>24)&0x000000FF);$y[$ii<<16]=(($aa[$ii]<<16)&0xFFFF0000)|(($aa[$ii]>>16)&0x0000FFFF);$x[$ii<<24]=(($aa[$ii]<<24)&0xFF000000)|(($aa[$ii]>>8)&0x00FFFFFF);$dd[$ii<<8]=(($this->dt3[$ii]<<8)&0xFFFFFF00)|(($ee[$ii]>>24)&0x000000FF);$cc[$ii<<16]=(($this->dt3[$ii]<<16)&0xFFFF0000)|(($ee[$ii]>>16)&0x0000FFFF);$bb[$ii<<24]=(($this->dt3[$ii]<<24)&0xFF000000)|(($ee[$ii]>>8)&0x00FFFFFF);}}function setKey($b){$this->key=$b;$this->changed=true;}function setIV($d){$this->encryptIV=$this->decryptIV=$this->iv=str_pad(substr($d,0,$this->block_size),$this->block_size,chr(0));;}function setKeyLength($jj){$jj>>=5;if($jj>8){$jj=8;}else if($jj<4){$jj=4;}$this->Nk=$jj;$this->key_size=$jj<<2;$this->explicit_key_length=true;$this->changed=true;}function setBlockLength($jj){$jj>>=5;if($jj>8){$jj=8;}else if($jj<4){$jj=4;}$this->Nb=$jj;$this->block_size=$jj<<2;$this->changed=true;}function _generate_xor($jj,&$d){$kk='';$q=$this->block_size;$ll=floor(($jj+($q-1))/$q);for($ii=0;$ii<$ll;$ii++){$kk.=$d;for($mm=4;$mm<=$q;$mm+=4){$nn=substr($d,-$mm,4);switch($nn){case  "\xFF\xFF\xFF\xFF":$d=substr_replace($d,"\x00\x00\x00\x00",-$mm,4);break;case  "\x7F\xFF\xFF\xFF":$d=substr_replace($d,"\x80\x00\x00\x00",-$mm,4);break2;default:extract(unpack('Ncount',$nn));$d=substr_replace($d,pack('N',$oo+1),-$mm,4);break2;}}}return $kk;}function encrypt($pp){$this->_setup();if($this->paddable){$pp=$this->_pad($pp);}$q=$this->block_size;$qq=&$this->enbuffer;$g=$this->continuousBuffer;$rr='';switch($this->mode){case  CRYPT_RIJNDAEL_MODE_ECB:for($ii=0;$ii<strlen($pp);$ii+=$q){$rr.=$this->_encryptBlock(substr($pp,$ii,$q));}break;case  CRYPT_RIJNDAEL_MODE_CBC:$kk=$this->encryptIV;for($ii=0;$ii<strlen($pp);$ii+=$q){$ss=substr($pp,$ii,$q);$ss=$this->_encryptBlock($ss^$kk);$kk=$ss;$rr.=$ss;}if($this->continuousBuffer){$this->encryptIV=$kk;}break;case  CRYPT_RIJNDAEL_MODE_CTR:$kk=$this->encryptIV;if(!empty($qq)){for($ii=0;$ii<strlen($pp);$ii+=$q){$ss=substr($pp,$ii,$q);$qq.=$this->_encryptBlock($this->_generate_xor($q,$kk));$b=$this->_string_shift($qq,$q);$rr.=$ss^$b;}}else{for($ii=0;$ii<strlen($pp);$ii+=$q){$ss=substr($pp,$ii,$q);$b=$this->_encryptBlock($this->_generate_xor($q,$kk));$rr.=$ss^$b;}}if($this->continuousBuffer){$this->encryptIV=$kk;if($tt=strlen($pp)%$q){$qq=substr($b,$tt).$qq;}}break;case  CRYPT_RIJNDAEL_MODE_CFB:if(!empty($qq['xor'])){$rr=$pp^$qq['xor'];$d=$qq['encrypted'].$rr;$tt=strlen($rr);$qq['encrypted'].=$rr;$qq['xor']=substr($qq['xor'],strlen($rr));}else{$rr='';$d=$this->encryptIV;$tt=0;}for($ii=$tt;$ii<strlen($pp);$ii+=$q){$ss=substr($pp,$ii,$q);$kk=$this->_encryptBlock($d);$d=$ss^$kk;if($g&&strlen($d)!=$q){$qq=array('encrypted'=>$d,'xor'=>substr($kk,strlen($d)));}$rr.=$d;}if($this->continuousBuffer){$this->encryptIV=$d;}break;case  CRYPT_RIJNDAEL_MODE_OFB:$kk=$this->encryptIV;if(strlen($qq)){for($ii=0;$ii<strlen($pp);$ii+=$q){$kk=$this->_encryptBlock($kk);$qq.=$kk;$b=$this->_string_shift($qq,$q);$rr.=substr($pp,$ii,$q)^$b;}}else{for($ii=0;$ii<strlen($pp);$ii+=$q){$kk=$this->_encryptBlock($kk);$rr.=substr($pp,$ii,$q)^$kk;}$b=$kk;}if($this->continuousBuffer){$this->encryptIV=$kk;if($tt=strlen($pp)%$q){$qq=substr($b,$tt).$qq;}}}return $rr;}function decrypt($rr){$this->_setup();if($this->paddable){$rr=str_pad($rr,(strlen($rr)+$this->block_size-1)%$this->block_size,chr(0));}$q=$this->block_size;$qq=&$this->debuffer;$g=$this->continuousBuffer;$pp='';switch($this->mode){case  CRYPT_RIJNDAEL_MODE_ECB:for($ii=0;$ii<strlen($rr);$ii+=$q){$pp.=$this->_decryptBlock(substr($rr,$ii,$q));}break;case  CRYPT_RIJNDAEL_MODE_CBC:$kk=$this->decryptIV;for($ii=0;$ii<strlen($rr);$ii+=$q){$ss=substr($rr,$ii,$q);$pp.=$this->_decryptBlock($ss)^$kk;$kk=$ss;}if($this->continuousBuffer){$this->decryptIV=$kk;}break;case  CRYPT_RIJNDAEL_MODE_CTR:$kk=$this->decryptIV;if(strlen($qq)){for($ii=0;$ii<strlen($rr);$ii+=$q){$ss=substr($rr,$ii,$q);$qq.=$this->_encryptBlock($this->_generate_xor($q,$kk));$b=$this->_string_shift($qq,$q);$pp.=$ss^$b;}}else{for($ii=0;$ii<strlen($rr);$ii+=$q){$ss=substr($rr,$ii,$q);$b=$this->_encryptBlock($this->_generate_xor($q,$kk));$pp.=$ss^$b;}}if($this->continuousBuffer){$this->decryptIV=$kk;if($tt=strlen($rr)%$q){$qq=substr($b,$tt).$qq;}}break;case  CRYPT_RIJNDAEL_MODE_CFB:if(!empty($qq['ciphertext'])){$pp=$rr^substr($this->decryptIV,strlen($qq['ciphertext']));$qq['ciphertext'].=substr($rr,0,strlen($pp));if(strlen($qq['ciphertext'])==$q){$kk=$this->_encryptBlock($qq['ciphertext']);$qq['ciphertext']='';}$tt=strlen($pp);$ss=$this->decryptIV;}else{$pp='';$kk=$this->_encryptBlock($this->decryptIV);$tt=0;}for($ii=$tt;$ii<strlen($rr);$ii+=$q){$ss=substr($rr,$ii,$q);$pp.=$ss^$kk;if($g&&strlen($ss)!=$q){$qq['ciphertext'].=$ss;$ss=$kk;}else if(strlen($ss)==$q){$kk=$this->_encryptBlock($ss);}}if($this->continuousBuffer){$this->decryptIV=$ss;}break;case  CRYPT_RIJNDAEL_MODE_OFB:$kk=$this->decryptIV;if(strlen($qq)){for($ii=0;$ii<strlen($rr);$ii+=$q){$kk=$this->_encryptBlock($kk);$qq.=$kk;$b=$this->_string_shift($qq,$q);$pp.=substr($rr,$ii,$q)^$b;}}else{for($ii=0;$ii<strlen($rr);$ii+=$q){$kk=$this->_encryptBlock($kk);$pp.=substr($rr,$ii,$q)^$kk;}$b=$kk;}if($this->continuousBuffer){$this->decryptIV=$kk;if($tt=strlen($rr)%$q){$qq=substr($b,$tt).$qq;}}}return $this->paddable?$this->_unpad($pp):$pp;}function _encryptBlock($uu){$vv=array();$ww=unpack('N*word',$uu);$o=$this->w;$x=$this->t0;$y=$this->t1;$z=$this->t2;$aa=$this->t3;$r=$this->Nb;$u=$this->Nr;$v=$this->c;$ii=0;foreach($ww as $xx){$vv[]=$xx^$o[0][$ii++];}$nn=array();for($yy=1;$yy<$u;$yy++){$ii=0;$mm=$v[1];$zz=$v[2];$aaa=$v[3];while($ii<$this->Nb){$nn[$ii]=$x[$vv[$ii]&0xFF000000]^$y[$vv[$mm]&0x00FF0000]^$z[$vv[$zz]&0x0000FF00]^$aa[$vv[$aaa]&0x000000FF]^$o[$yy][$ii];$ii++;$mm=($mm+1)%$r;$zz=($zz+1)%$r;$aaa=($aaa+1)%$r;}for($ii=0;$ii<$r;$ii++){$vv[$ii]=$nn[$ii];}}for($ii=0;$ii<$r;$ii++){$vv[$ii]=$this->_subWord($vv[$ii]);}$ii=0;$mm=$v[1];$zz=$v[2];$aaa=$v[3];while($ii<$this->Nb){$nn[$ii]=($vv[$ii]&0xFF000000)^($vv[$mm]&0x00FF0000)^($vv[$zz]&0x0000FF00)^($vv[$aaa]&0x000000FF)^$o[$u][$ii];$ii++;$mm=($mm+1)%$r;$zz=($zz+1)%$r;$aaa=($aaa+1)%$r;}$vv=$nn;array_unshift($vv,'N*');return call_user_func_array('pack',$vv);}function _decryptBlock($uu){$vv=array();$ww=unpack('N*word',$uu);$bbb=count($vv);$p=$this->dw;$bb=$this->dt0;$cc=$this->dt1;$dd=$this->dt2;$ee=$this->dt3;$r=$this->Nb;$u=$this->Nr;$v=$this->c;$ii=0;foreach($ww as $xx){$vv[]=$xx^$p[$u][$ii++];}$nn=array();for($yy=$u-1;$yy>0;$yy--){$ii=0;$mm=$r-$v[1];$zz=$r-$v[2];$aaa=$r-$v[3];while($ii<$r){$nn[$ii]=$bb[$vv[$ii]&0xFF000000]^$cc[$vv[$mm]&0x00FF0000]^$dd[$vv[$zz]&0x0000FF00]^$ee[$vv[$aaa]&0x000000FF]^$p[$yy][$ii];$ii++;$mm=($mm+1)%$r;$zz=($zz+1)%$r;$aaa=($aaa+1)%$r;}for($ii=0;$ii<$r;$ii++){$vv[$ii]=$nn[$ii];}}$ii=0;$mm=$r-$v[1];$zz=$r-$v[2];$aaa=$r-$v[3];while($ii<$r){$nn[$ii]=$p[0][$ii]^$this->_invSubWord(($vv[$ii]&0xFF000000)|($vv[$mm]&0x00FF0000)|($vv[$zz]&0x0000FF00)|($vv[$aaa]&0x000000FF));$ii++;$mm=($mm+1)%$r;$zz=($zz+1)%$r;$aaa=($aaa+1)%$r;}$vv=$nn;array_unshift($vv,'N*');return call_user_func_array('pack',$vv);}function _setup(){static $ccc=array(0,0x01000000,0x02000000,0x04000000,0x08000000,0x10000000,0x20000000,0x40000000,0x80000000,0x1B000000,0x36000000,0x6C000000,0xD8000000,0xAB000000,0x4D000000,0x9A000000,0x2F000000,0x5E000000,0xBC000000,0x63000000,0xC6000000,0x97000000,0x35000000,0x6A000000,0xD4000000,0xB3000000,0x7D000000,0xFA000000,0xEF000000,0xC5000000,0x91000000);if(!$this->changed){return;}if(!$this->explicit_key_length){$jj=strlen($this->key)>>2;if($jj>8){$jj=8;}else if($jj<4){$jj=4;}$this->Nk=$jj;$this->key_size=$jj<<2;}$this->key=str_pad(substr($this->key,0,$this->key_size),$this->key_size,chr(0));$this->encryptIV=$this->decryptIV=$this->iv=str_pad(substr($this->iv,0,$this->block_size),$this->block_size,chr(0));$this->Nr=max($this->Nk,$this->Nb)+6;switch($this->Nb){case 4:case 5:case 6:$this->c=array(0,1,2,3);break;case 7:$this->c=array(0,1,2,4);break;case 8:$this->c=array(0,1,3,4);}$b=$this->key;$o=array_values(unpack('N*words',$b));$jj=$this->Nb*($this->Nr+1);for($ii=$this->Nk;$ii<$jj;$ii++){$nn=$o[$ii-1];if($ii%$this->Nk==0){$nn=(($nn<<8)&0xFFFFFF00)|(($nn>>24)&0x000000FF);$nn=$this->_subWord($nn)^$ccc[$ii/$this->Nk];}else if($this->Nk>6&&$ii%$this->Nk==4){$nn=$this->_subWord($nn);}$o[$ii]=$o[$ii-$this->Nk]^$nn;}$nn=array();for($ii=$ddd=$eee=0;$ii<$jj;$ii++,$eee++){if($eee==$this->Nb){if($ddd==0){$this->dw[0]=$this->w[0];}else{$mm=0;while($mm<$this->Nb){$p=$this->_subWord($this->w[$ddd][$mm]);$nn[$mm]=$this->dt0[$p&0xFF000000]^$this->dt1[$p&0x00FF0000]^$this->dt2[$p&0x0000FF00]^$this->dt3[$p&0x000000FF];$mm++;}$this->dw[$ddd]=$nn;}$eee=0;$ddd++;}$this->w[$ddd][$eee]=$o[$ii];}$this->dw[$ddd]=$this->w[$ddd];$this->changed=false;}function _subWord($xx){static $fff,$ggg,$hhh,$iii;if(empty($fff)){$fff=array(0x63,0x7C,0x77,0x7B,0xF2,0x6B,0x6F,0xC5,0x30,0x01,0x67,0x2B,0xFE,0xD7,0xAB,0x76,0xCA,0x82,0xC9,0x7D,0xFA,0x59,0x47,0xF0,0xAD,0xD4,0xA2,0xAF,0x9C,0xA4,0x72,0xC0,0xB7,0xFD,0x93,0x26,0x36,0x3F,0xF7,0xCC,0x34,0xA5,0xE5,0xF1,0x71,0xD8,0x31,0x15,0x04,0xC7,0x23,0xC3,0x18,0x96,0x05,0x9A,0x07,0x12,0x80,0xE2,0xEB,0x27,0xB2,0x75,0x09,0x83,0x2C,0x1A,0x1B,0x6E,0x5A,0xA0,0x52,0x3B,0xD6,0xB3,0x29,0xE3,0x2F,0x84,0x53,0xD1,0x00,0xED,0x20,0xFC,0xB1,0x5B,0x6A,0xCB,0xBE,0x39,0x4A,0x4C,0x58,0xCF,0xD0,0xEF,0xAA,0xFB,0x43,0x4D,0x33,0x85,0x45,0xF9,0x02,0x7F,0x50,0x3C,0x9F,0xA8,0x51,0xA3,0x40,0x8F,0x92,0x9D,0x38,0xF5,0xBC,0xB6,0xDA,0x21,0x10,0xFF,0xF3,0xD2,0xCD,0x0C,0x13,0xEC,0x5F,0x97,0x44,0x17,0xC4,0xA7,0x7E,0x3D,0x64,0x5D,0x19,0x73,0x60,0x81,0x4F,0xDC,0x22,0x2A,0x90,0x88,0x46,0xEE,0xB8,0x14,0xDE,0x5E,0x0B,0xDB,0xE0,0x32,0x3A,0x0A,0x49,0x06,0x24,0x5C,0xC2,0xD3,0xAC,0x62,0x91,0x95,0xE4,0x79,0xE7,0xC8,0x37,0x6D,0x8D,0xD5,0x4E,0xA9,0x6C,0x56,0xF4,0xEA,0x65,0x7A,0xAE,0x08,0xBA,0x78,0x25,0x2E,0x1C,0xA6,0xB4,0xC6,0xE8,0xDD,0x74,0x1F,0x4B,0xBD,0x8B,0x8A,0x70,0x3E,0xB5,0x66,0x48,0x03,0xF6,0x0E,0x61,0x35,0x57,0xB9,0x86,0xC1,0x1D,0x9E,0xE1,0xF8,0x98,0x11,0x69,0xD9,0x8E,0x94,0x9B,0x1E,0x87,0xE9,0xCE,0x55,0x28,0xDF,0x8C,0xA1,0x89,0x0D,0xBF,0xE6,0x42,0x68,0x41,0x99,0x2D,0x0F,0xB0,0x54,0xBB,0x16);$ggg=array();$hhh=array();$iii=array();for($ii=0;$ii<256;$ii++){$ggg[$ii<<8]=$fff[$ii]<<8;$hhh[$ii<<16]=$fff[$ii]<<16;$iii[$ii<<24]=$fff[$ii]<<24;}}return $fff[$xx&0x000000FF]|$ggg[$xx&0x0000FF00]|$hhh[$xx&0x00FF0000]|$iii[$xx&0xFF000000];}function _invSubWord($xx){static $fff,$ggg,$hhh,$iii;if(empty($fff)){$fff=array(0x52,0x09,0x6A,0xD5,0x30,0x36,0xA5,0x38,0xBF,0x40,0xA3,0x9E,0x81,0xF3,0xD7,0xFB,0x7C,0xE3,0x39,0x82,0x9B,0x2F,0xFF,0x87,0x34,0x8E,0x43,0x44,0xC4,0xDE,0xE9,0xCB,0x54,0x7B,0x94,0x32,0xA6,0xC2,0x23,0x3D,0xEE,0x4C,0x95,0x0B,0x42,0xFA,0xC3,0x4E,0x08,0x2E,0xA1,0x66,0x28,0xD9,0x24,0xB2,0x76,0x5B,0xA2,0x49,0x6D,0x8B,0xD1,0x25,0x72,0xF8,0xF6,0x64,0x86,0x68,0x98,0x16,0xD4,0xA4,0x5C,0xCC,0x5D,0x65,0xB6,0x92,0x6C,0x70,0x48,0x50,0xFD,0xED,0xB9,0xDA,0x5E,0x15,0x46,0x57,0xA7,0x8D,0x9D,0x84,0x90,0xD8,0xAB,0x00,0x8C,0xBC,0xD3,0x0A,0xF7,0xE4,0x58,0x05,0xB8,0xB3,0x45,0x06,0xD0,0x2C,0x1E,0x8F,0xCA,0x3F,0x0F,0x02,0xC1,0xAF,0xBD,0x03,0x01,0x13,0x8A,0x6B,0x3A,0x91,0x11,0x41,0x4F,0x67,0xDC,0xEA,0x97,0xF2,0xCF,0xCE,0xF0,0xB4,0xE6,0x73,0x96,0xAC,0x74,0x22,0xE7,0xAD,0x35,0x85,0xE2,0xF9,0x37,0xE8,0x1C,0x75,0xDF,0x6E,0x47,0xF1,0x1A,0x71,0x1D,0x29,0xC5,0x89,0x6F,0xB7,0x62,0x0E,0xAA,0x18,0xBE,0x1B,0xFC,0x56,0x3E,0x4B,0xC6,0xD2,0x79,0x20,0x9A,0xDB,0xC0,0xFE,0x78,0xCD,0x5A,0xF4,0x1F,0xDD,0xA8,0x33,0x88,0x07,0xC7,0x31,0xB1,0x12,0x10,0x59,0x27,0x80,0xEC,0x5F,0x60,0x51,0x7F,0xA9,0x19,0xB5,0x4A,0x0D,0x2D,0xE5,0x7A,0x9F,0x93,0xC9,0x9C,0xEF,0xA0,0xE0,0x3B,0x4D,0xAE,0x2A,0xF5,0xB0,0xC8,0xEB,0xBB,0x3C,0x83,0x53,0x99,0x61,0x17,0x2B,0x04,0x7E,0xBA,0x77,0xD6,0x26,0xE1,0x69,0x14,0x63,0x55,0x21,0x0C,0x7D);$ggg=array();$hhh=array();$iii=array();for($ii=0;$ii<256;$ii++){$ggg[$ii<<8]=$fff[$ii]<<8;$hhh[$ii<<16]=$fff[$ii]<<16;$iii[$ii<<24]=$fff[$ii]<<24;}}return $fff[$xx&0x000000FF]|$ggg[$xx&0x0000FF00]|$hhh[$xx&0x00FF0000]|$iii[$xx&0xFF000000];}function enablePadding(){$this->padding=true;}function disablePadding(){$this->padding=false;}function _pad($jjj){$jj=strlen($jjj);if(!$this->padding){if($jj%$this->block_size==0){return $jjj;}else{user_error("The plaintext's length ($jj) is not a multiple of the block size ({$this->block_size})",E_USER_NOTICE);$this->padding=true;}}$kkk=$this->block_size-($jj%$this->block_size);return str_pad($jjj,$jj+$kkk,chr($kkk));}function _unpad($jjj){if(!$this->padding){return $jjj;}$jj=ord($jjj[strlen($jjj)-1]);if(!$jj||$jj>$this->block_size){return false;}return substr($jjj,0,-$jj);}function enableContinuousBuffer(){$this->continuousBuffer=true;}function disableContinuousBuffer(){$this->continuousBuffer=false;$this->encryptIV=$this->iv;$this->decryptIV=$this->iv;}function _string_shift(&$lll,$mmm=1){$nnn=substr($lll,0,$mmm);$lll=substr($lll,$mmm);return $nnn;}}