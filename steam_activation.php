<?php 

//Deny direct initialization for extra security
if(!defined("IN_MYBB")) {
    die("You Cannot Access This File Directly. Please Make Sure IN_MYBB Is Defined.");
}

//Hooks
$plugins->add_hook("global_start", "steam_activation_global_start");
$plugins->add_hook("member_do_register_end", "steam_activation_member_do_register_end");
$plugins->add_hook("task_hourlycleanup", "steam_activation_task_hourlycleanup");
$plugins->add_hook("task_dailycleanup_start", "steam_activation_task_dailycleanup_start");
$plugins->add_hook("admin_config_settings_change_commit", "steam_activation_admin_config_settings_change_commit");

//Plugin information
function steam_activation_info() {
	steam_activation_f_debug("Primitive function: info()");

    return array(
        "name"  => "Steam Account Activation",
        "description"=> 'This plugin verifies/activates new forum users via Steam login.<br>
        <b>NOTE</b> This plugin needs a specific OpenID library to connect to the SteamAPI. Download it <a href="https://github.com/opauth/openid/blob/master/Vendor/lightopenid/openid.php">here</a> and put it in the /inc/plugins folder (the same folder that this plugin is in).<br>
        Full link to the OpenID file: https://github.com/opauth/openid/blob/master/Vendor/lightopenid/openid.php',
        "website"        => "https://clwo.eu",
        "author"        => "Square Play'n",
        "authorsite"    => "http://squareplayn.noip.me",
        "version"        => "1.0",
        "guid"             => "",
        "compatibility" => "18*"
    );
}

//Return if plugin is installed
function steam_activation_is_installed() {
	steam_activation_f_debug("Primitive function: is_installed()");
	global $db;

	//Look for the steamid field
	return $db->field_exists("steam_activation_steamid", "users");
}

//Installation procedure for plugin
function steam_activation_install() {
	steam_activation_f_debug("Primitive function: install()");
    global $db, $cache;

    //Add the row in which we will put the steam id's
    steam_activation_f_debug("Adding steam_activation_steamid row to table:users");
    $db->add_column("users", "steam_activation_steamid", "VARCHAR(17) NULL DEFAULT NULL");

    
    /****** User field setup *******/

    //Add the prifilefield settings
    steam_activation_f_debug("Adding profilefield to table:profilefields");
    $profilefield = array(
    	"name" 			=> "Steam profile",
    	"description" 	=> "A link to the users Steam profile.",
    	"type" 			=> "text",
    	"maxlength"	 	=> "150",
    	"viewableby" 	=> "-1",
    	"allowmycode" 	=> "1"
    );
    $userfieldID = $db->insert_query("profilefields", $profilefield);

    //Update the display order now that we not the ID of the field
    steam_activation_f_debug("Updating the profilefield's disporder to be the bottom one");
    $db->update_query("profilefields", array("disporder" => $userfieldID), "fid=".$userfieldID);

    //Add the actual row to store the uservalues in
	steam_activation_f_debug("Adding userfields column to table:userfields");
    if(!$db->field_exists("fid".$userfieldID, "userfields")) {
    	//Field does not already exists
    	steam_activation_f_debug("Good: the userfields column did not exist yet");
    	$db->add_column("userfields", "fid".$userfieldID, "TEXT NOT NULL");
    } else {
    	//Field already existed
    	steam_activation_f_debug("Bad: the userfields column already existed. If there was usefull info in it, it might be overwritten and depending on the setup of the table the plugin might not store its steamlinks correctly. Take a look at the userfields disporder settings in the administration panel and see who is taking disporder ".$userfieldID);
    }

    //Store the ID of the userfield value
    steam_activation_f_debug("Storing the ID of the userfield in a setting with gid=0 in table:settings");
    $setting = array(
        "name"        	=> "steam_activation_userfieldID",
        "title"         => "The ID of the userfield",
        "description"   => "This stored the userfield ID and has gid 0 since it should not be seen in the settings.",
        "optionscode"   => "yesno",
        "value"        	=> $userfieldID,
        "disporder"     => "0",
        "gid"           => "0",
    );
    $db->insert_query("settings", $setting);

    //Update the userfields cache
    steam_activation_f_debug("Updating the profilefields cache");
    $cache->update_profilefields();

   
    /****** SETTINGS *******/

    //Setup settings group
    steam_activation_f_debug("Adding the settings group to table:settinggroups");
    $settings_group = array(
        "gid"    		=> "NULL",
        "name" 		 	=> "steam_activation",
        "title"      	=> "Steam Account Activation",
        "description"   => "Settings For the Steam Activation Plugin",
        "disporder"    	=> "1",
        "isdefault"  	=> "no",
    );
    $gid = $db->insert_query("settinggroups", $settings_group);

    //All the actual settings fields
    steam_activation_f_debug("Initializing all settings");
    $settings_array = array(
		
		"steam_activation_non_activated_group" => array(
	        "title"			=> "Non-Activated users group",
	        "description"	=> "Select the group that users are when they first sign up. <br> 
	            NOTE: This is not a preference, rather a piece of info that the plugin needs in order for it to function properly.",
	        "optionscode"  	=> "groupselectsingle",
	        "value"       	=> "5",
	        "disporder"     => "1"
        ),

    	"steam_activation_activated_group" => array(
	        "title"          => "Activated users group",
	        "description"    => "Select the group that users should become when they activated their account.<br>
	            NOTE: Feel free to change this to whichever group you prefer.
	            The plugin will function either way.",
	        "optionscode" 	=> "groupselectsingle",
	        "value"        	=> "2",
	        "disporder"     => "2"
	    ),

	    "steam_activation_banned_group" => array(
	        "title"          => "Banned users group",
	        "description"    => "Select the group that users should become when they are banned.<br>
	            NOTE: Feel free to change this to whichever group you prefer.
	            The plugin will function either way.<br>
	            For more info on why this plugin needs this and when it will ban, read the other settings, but do not worry, banning is disabled by default.",
	        "optionscode" 	=> "groupselectsingle",
	        "value"        	=> "7",
	        "disporder"     => "3"
	    ),

	    "steam_activation_introduction" => array(
	        "title"         => "Introduction posting",
	        "description"   => "Should new users make an introduction thread? When force, they do not get activated before they post an introduction. When optional, they will be given the option to either post an introduction or to skip it.<br>
	            Users that are already activated at the time of switching this value will not be asked to post an introduction. The introduction only goes for people that newly register an account.",
	        "optionscode"   => "select\noff=Off\noptional=Optional\nforce=Force",
	        "value"        	=> "optional",
	        "disporder"     => "4"
        ),

    	"steam_activation_introduction_forum" => array(
	        "title"         => "Introductions forum",
	        "description"   => "If introduction posting is set to either Force or Optional, select the forum in which you want the introduction threads to appear.<br>NOTE: Make sure that non-activated users have post permissions in this thread!<br>
	        	You might also want to think about the fact that users can probably delete their own introduction posts, making their registration unseen. You can disable CanDeleteOwnThreads in the forum custom permissions for the Registered-group to prevent this.",
	        "optionscode"   => "forumselectsingle",
	        "value"        	=> "2",
	        "disporder"     => "5"
        ),
    	
		"steam_activation_show_profile" => array(
	        "title"         => "Show Steam link on profile pages",
	        "description"   => "Show a link to the users Steam account on his forum profile page.",
	        "optionscode"   => "yesno",
	        "value"        	=> "1",
	        "disporder"     => "6"
	    ),

		"steam_activation_show_postbit" => array(
	        "title"         => "Show Steam link on postbits",
	        "description"   => "Show a link to the users Steam account on the headers of hist posts.",
	        "optionscode"   => "yesno",
	        "value"        	=> "1",
	        "disporder"     => "7"
	    ),

	    "steam_activation_allow_duplicate" => array(
	    	"title" 		=> "Allow Steam Duplicates",
	    	"description"	=> "Allow multiple forum accounts to have the same Steam ID",
	    	"optionscode"	=> "yesno",
	    	"value"			=> "0",
	    	"disporder"		=> "8"
	    ),

	    "steam_activation_template_steam" => array(
	    	"title"			=> "Steam page template",
	    	"description"	=> '
	    	This is the template for the Steam login page that users get to see when they have to login with Steam.<br>
	    	You can use HTML. <br>
	    	MyBB codes will NOT work! <br>
	    	You can use {codes} that will automatically be replaced by the value, for example {username} will be replaced by the username for each user.<br>
	    	The following codes MUST be used for the page to be functional: {logoutlink} and {steamloginbutton}.<br>
	    	<br>
	    	<b>Standard codes:</b><br>
	    	{username} {userid} Direct values / text<br>
	    	</br>
	    	<b>Other codes you can use in just this template</b>
	    	{logoutlink} The href for the logout link<br>
	    	{steamloginbutton} The Login With Steam button/picture<br>
	    	{loginFailed} {loginCancelled} {duplicate} {steamDown} These refer to templates in the next setting and only show when the error itself is actually thrown.<br>
	    	',
	    	"optionscode"	=> "textarea",
	    	"value"			=> '
<body style="text-align: center;">
<br>
<h1>Welcome to the Steam-login page, {username}!</h1><br>
Please login with your Steam account.<br>
You will only have to do this once, to verify your account.<br>
{loginFailed}{loginCancelled}{duplicate}{steamDown}
<br>
<a href="{logoutlink}">Logout of forum account</a><br>
{steamloginbutton}<br>
</body>
	    	',
	    	"disporder"		=> "9"
	    ),

	    "steam_activation_template_steam_errors" => array(
	    	"title"			=> "Steam errors template",
	    	"description"	=> "
	    	These are the templates for every possible error that can occur.<br>
	    	You will need to specify every single errors template separated by a comma. You can still use the above standard {codes}, and also the specific codes that an error comes with. For example, {duplicate} comes with {dupicateUID} {duplicateUsername} (the userinfo of the user that is already registered with that steamid)<br>
	    	You insert these templates in the following way: {loginFailed}Hi {username}, your login failed.;{loginCancelled}Login cancelled;e.t.c. <br>
	    	Therefore, you cannot use ; { and } in your text. <br>
	    	<br>
	    	You should make a template for each custom error your custom checks throw (check below for more info on custom checks), as well as the following standard-errors:<br>
	    	{loginFailed} When something went wrong during Steam login<br>
	    	{loginCancelled} When the user has cancelled his login<br>
	    	{duplicate} The steam-id is already used by a different user. Comes with {duplicateUID} and {duplicateUsername}<br>
	    	{steamDown} When Steam is down, this gets shown (and the login-button will not do anything)
	    	",
	    	"optionscode"	=> "textarea",
	    	"value"			=> "{loginFailed}<b><br>Error: login failed. Please try again.<br><br></b>;{loginCancelled}<b><br>Error: login cancelled. Please try again.<br><br></b>;{duplicate}<b><br><br>Error: Another account {duplicateUsername} was already registered with this Steam account. <br>Either log in with a different account, or contact an admin.<br><br></b>;{steamDown}<b><br><br><b>Error: Steam is down. Try again when Steam is up again. Sometimes, simply refreshing the page solves the problem.</b><br><br></b>",
	    	"disporder"		=> "10"
	    ),

	    "steam_activation_template_introduction" => array(
	    	"title"			=> "Introduction page template",
	    	"description"	=> "
	    	This is the template for the introduction page.<br>
	    	It works just like the Steam page template.<br>
	    	You must include at least a link with the {logoutlink}, {formstart},{editorstart},{editorend}, a submit button to post the introduction and {formend}.<br>
	    	If you have the introcution-setting set to OFF (no introductions), you do not need this template. <br>
	    	<br>
	    	<b>Available codes:</b><br>
	    	All standard codes specified further above.<br>
	    	{steamid} The users steam ID<br>
	    	{logoutlink} The link that lets people log out of their forum account. <br>
	    	{formstart} {formend} The form data used. This will not output any text. {editorstart},{editorend} and the submit button to should be inbetween {formstart} and {formend}.<br>
	    	{editorstart}{editorend} The introduction post form in which people can type their introduction. In between these 2, you can put the value that is in the field by default (for example, you can put some questions in there for people to answer).<br>
	    	{skip} The skip link specified in the template beneath. Only called when the introductions are optional.
	    	",
	    	"optionscode"	=> "textarea",
	    	"value"			=> '
<body style="text-align: center;">
<h1>Introduction post</h1><br>
It is time to post an introduction now!<br>
Tell us something about yourself, we would love to get to know you.<br>
After you have posted a basic introduction here, you can choose to edit it using the usual MyBB editor.<br>
Once you post your introduction, you have done all the steps and your account will automatically be activated.<br>
<br>
{skip}
<a href="{logoutlink}">Logout of forum account</a><br>
{formstart}
{editorstart}
Hi, I am {username}. I live in ...
{editorend}<br>
<input type="submit" value="Post introduction" /><br>
{formend}
</body>
	    	', 
	    	"disporder"		=> "11"
	    ),

	    "steam_activation_template_introduction_skip" => array(
	    	"title"			=> "Introduction page skip template",
	    	"description"	=> 'This is the template for the skip link that people get to see when they are allowed to skip posting an introduction.<br>
	    	You can use the standard {codes}, but also {skiplink} for the address that you should give this button/link.', 
	    	"optionscode"	=> "textarea",
	    	"value"			=> 'You may <a href="{skiplink}">skip</a> this.<br>',
	    	"disporder"		=> "12"
	    ),

	    "steam_activation_template_introduction_subject" => array(
	    	"title"			=> "Introduction Thread Subject",
	    	"description"	=> 'This is (the template for) the subject that a post gets when posting an introduction.<br>
	    	You can use the standard {codes} like {username}.', 
	    	"optionscode"	=> "textarea",
	    	"value"			=> 'Introduction of {username}',
	    	"disporder"		=> "13"
	    ),

	    "steam_activation_custom_checks" => array(
	    	"title"			=> "Custom checks for steamID",
	    	"description"	=> 'An expantion option: Code your own checks that define if a certain user is allowed on the forums or not. The values to put in this field are the names of the file (exluding .php), separated by commas. Leave empty if unused.<br>
	    		E.g. "customCheckSteamReputation, customCheckSpammerUsingPostCount" <br>
	    		Note that for every custom check you add, you must also add it to the above templates. <br>
	    		These custom plugins also optionally run every hour or day on all users, more info below.<br>
	    		<br>
	    		<b>How to code such plugin:</b><br>
	    		* Pick a name, I will use customCheckName<br>
	    		* Put a file customCheckName.php in the mybb plugin directory<br>
	    		* That file should contain the function customCheckName($forumUserId, $steamid) {} <br>
	    		* $steamid contains: the steamid that should be checked, if present. <br>
	    		* Remember that you have acces to the global $db (database) as well as the user id, from which you can gather more info. <br>
	    		* The function should return an array with  at least array["error"] containing the error (e.g. "spammer"). When no error was found, return an empty array. <br>
	    		* You may return a custom array-value array["banreason"] with the reason for the user to be banned for, and array["banlength"] with the length to ban for (in seconds), 0 for no ban or -1 for a permanent ban.<br>
	    		* Any other info returned in the array can be used in the displaying of the error to the user using the editor above. <br>
	    		  Just like you could put {duplicate}, you can also put {spammer} and {spammerName} in the templates above.', 
	    	"optionscode"	=> "text",
	    	"value"			=> "",
	    	"disporder"		=> "14"
	    ),

	    "steam_activation_checktask" => array(
	        "title"         => "Hourly/daily user-check",
	        "description"   => 'This option allows to check EVERY forum-user against the customchecks above to see if they are still allowed on the forums. If any returnedarray["error"] (a.k.a. a reason why whis user is not allowed) is returned by any plugin, the user will be banned for returnedarray["bantime"] minutes, where 0 minutes make for no banning for this plugin at all (default=permanent) with reason returnedarray["banreason"] (default=automatic ban), or unbanned if returnedarray["unban"] is set to true.',
	        "optionscode"   => "select\noff=Off\nhourly=Hourly\ndaily=Daily",
	        "value"        	=> "off",
	        "disporder"     => "15"
        ),

        "steam_activation_checkuseronglobalstart" => array(
        	"title"			=> "Check user on page start",
        	"description"	=> "If yes, whenever a user requests a page on your site, he is quickly checked against the custom checker functions that you can define above. If anything comes out of this, it will be handeled in the way described in the previous setting.",
        	"optionscode"	=> "yesno",
        	"value"			=> "0",
        	"disporder"		=> "16"
        ),

        "steam_activation_curl" => array(
        	"title"			=> "Use CURL to check if Steam is down",
        	"description"	=> "If you do not have CURL installed, or want to not use CURL for any other reason, you can switch this to NO. The loginpage might take upto 30 seconds to load if you turn off CURL.",
        	"optionscode"	=> "yesno",
        	"value"			=> "0",
        	"disporder"		=> "17"

        ),

    	"steam_activation_debug" => array(
	        "title"         => "Debug messages",
	        "description"   => "Turn on debug messages.<br>I hope you do not need this.",
	        "optionscode"   => "yesno",
	        "value"        	=> "0",
	        "disporder"     => "18"
	    )
    );
    
    //Add all the settings to the database
    steam_activation_f_debug("Looping through every setting");
    foreach($settings_array as $name => $setting) {
    	steam_activation_f_debug("Adding setting ".$name." to table:settings");
    	$setting["name"] = $name;
    	$setting["gid"] = $gid;
    	$db->insert_query("settings", $setting);
    }

    //Update the settings file
    steam_activation_f_debug("Updating the settings pages");
    rebuild_settings();
}

//Uninstall procedure for plugin
function steam_activation_uninstall() {
	steam_activation_f_debug("Primitive function: uninstall()");
	global $mybb, $db, $cache;

    //Clean up user row with steam ID's
    steam_activation_f_debug("Dropping column steamid in table:users");
    $db->drop_column("users", "steam_activation_steamid");

    //Delete the userfield column
    steam_activation_f_debug("Dropping the userfield column in table: userfileds");
    $db->drop_column("userfields", "fid".$mybb->settings["steam_activation_userfieldID"]);

    //Delete the settings for the userfield
    steam_activation_f_debug("Deleting the settings for the userfield in table:profilefields");
    $db->delete_query("profilefields", "fid='".$mybb->settings["steam_activation_userfieldID"]."'");

    //Update the userfields that are still in cache
    steam_activation_f_debug("Updating the profilefields cache");
    $cache->update_profilefields();

    //Clean up settings
    steam_activation_f_debug("Deleting the settings of this plugin from tables:settings&settinggroups");
    $db->delete_query("settings", "name LIKE ('steam_activation_%')");
    $db->delete_query("settinggroups", "name='steam_activation'");
    
    steam_activation_f_debug("Updating the settings pages");
    rebuild_settings();
}

//Activation procedure for plugin
function steam_activation_activate() {
	steam_activation_f_debug("Primitive function: activate()");
	global $mybb, $db, $cache;

	steam_activation_f_debug("Enabeling the setting for the userfield showing in the profiles and postbits according to the settings");
	steam_activation_f_profilefieldsettings_update();
}

//Deactivation procedure for plugin
function steam_activation_deactivate() {
	steam_activation_f_debug("Primitive function: deactivate()");
	global $mybb, $db, $cache;

	steam_activation_f_debug("Disabeling the profilefields in the profiles and postbits");
	$db->update_query("profilefields", array("profile" => "0", "postbit" => "0"), "fid=".$mybb->settings["steam_activation_userfieldID"]);

	steam_activation_f_debug("Updating profilefields cache");
	$cache->update_profilefields();
}

/********* Hooks *********************/

//Global_start hook
function steam_activation_global_start() {
	steam_activation_f_debug("Hook: Global_start()");
	global $mybb;

	//Check if debug values are on
	if($mybb->settings["steam_activation_debug"]) {
		error_reporting(E_ALL); ini_set('display_errors', 'On');
	}

	steam_activation_f_run_precheck();
}

//Member_do_register_end hook
function steam_activation_member_do_register_end() {
	steam_activation_f_debug("Hook: member_do_register_end()");

	//Refresh page
	steam_activation_f_debug("Probably not seen as logged in yet, so refreshing the page so MyBB detects the user as logged in and the data is accessable");
	steam_activation_f_refresh();
}

//Task_hourlycleanup hook
function steam_activation_task_hourlycleanup() {
	steam_activation_f_debug("Hook: task_hourlycleanup()");
	global $mybb;

	if($mybb->settings["steam_activation_checktask"] === "hourly") {
		steam_activation_f_debug("Setting for checking all users is set to hourly, I'm going to run!");
		
		steam_activation_f_bancheck_allusers();

	} else {
		steam_activation_f_debug("Setting for checking all users is not set to hourly, I'm out!");

	}
}

//Task_dailycleanup_start hook
function steam_activation_task_dailycleanup_start() {
	steam_activation_f_debug("Hook: task_dailycleanup()");
	global $mybb;
	
	if($mybb->settings["steam_activation_checktask"] === "daily") {
		steam_activation_f_debug("Setting for checking all users is set to daily, I'm going to run!");

		steam_activation_f_bancheck_allusers();

	} else {
		steam_activation_f_debug("Setting for checking all users is not set to daily, I'm out!");

	}
}

//Admin_config_settings_change_commit
function steam_activation_admin_config_settings_change_commit() {
	steam_activation_f_debug("Hook: admin_config_settings_change_commit");

	steam_activation_f_profilefieldsettings_update();
}

/******* Plugin specific functions *********/

//Handle debug messages
function steam_activation_f_debug($message) {
	global $mybb;

	if($mybb->settings["steam_activation_debug"]) {
		echo("<b>[Steam Activation][".time()."]</b> ".$message."<br>");
	}
}

//Pre-condition checks before we possibly run any pages
function steam_activation_f_run_precheck() {
	steam_activation_f_debug("Function: run_precheck()");
	global $mybb;

	if($mybb->user["uid"]) {
		steam_activation_f_debug("User is logged in");
		//Logged in

		if($mybb->user["usergroup"] == $mybb->settings["steam_activation_banned_group"]) {
			steam_activation_f_debug("User is banned, I'm out!");
			//User is banned already

		} else {
			steam_activation_f_debug("User is not banned");

			//Check if the current user should be banned
			if($mybb->settings["steam_activation_checkuseronglobalstart"]) {
				steam_activation_f_debug("Usercecking on globalstart enabled, going to check current user");

				steam_activation_f_bancheck_currentuser();
			} else {
				steam_activation_f_debug("No userchecking on globalstart, continuing");
			}

			if(!isset($mybb->user["steam_activation_steamid"]) || 
				$mybb->user["usergroup"] == $mybb->settings["steam_activation_non_activated_group"]) {
				steam_activation_f_debug("This user is not properly setup, lets run the plugin page");
				//User not properly setup

				//Check once if the user should already just be activated
				if(steam_activation_f_should_be_activated()) {
					steam_activation_f_activate_user();
					steam_activation_f_debug("I am not dying since the user did already meet the requirements to be activated and has been activated now, so he is allowed to see the page that he requested");
				} else {
					steam_activation_f_debug("User should not be activated already, proceeding");

					//Check for allowed pages
					if(steam_activation_f_get_requestedpage() === steam_activation_f_get_logoutlink()) {
						steam_activation_f_debug("This is the exact logout page, I'm out!");

					} else if(steam_activation_f_get_requestedpage() === steam_activation_f_get_postlink()) {
						steam_activation_f_debug("This is the post-thread page for the introduction thread. Let's check if this user has verified Steam already");
						if(isset($mybb->user["steam_activation_steamid"])) {
							steam_activation_f_debug("Steam is set, this guy is allowed to post an introduction, plugin's out!");

						} else {
							steam_activation_f_debug("This guy has not yet verified Steam, what's he doing here? Pulling you over to the Steam login page!");

							steam_activation_f_run();
						}	

					} else {
						steam_activation_f_debug("This is not one of the allowed pages (e.g. the logout page). Let's run!");

						steam_activation_f_run();
					}
				}
			} else {
				steam_activation_f_debug("User already properly setup, plugin done");
			}
		}
	} else {
		steam_activation_f_debug("User is not logged in, plugin done");
	}
}

//Main function to run the logic
function steam_activation_f_run() {
	//@pre: Logged in and either No steam or not verified, nor on specific function, and not verifyable yet
	global $mybb;

	if(!isset($mybb->user['steam_activation_steamid'])) {
		steam_activation_f_debug("No steam id found");
		//No steam set

		steam_activation_f_steam_handler();

	} else {
		steam_activation_f_debug("Steam id found");
		//Yes Steam set

		 if($mybb->user['usergroup'] == $mybb->settings['steam_activation_non_activated_group']) {
		 	steam_activation_f_debug("User not yet activated");
		 	//User not activated yet

		 	steam_activation_f_intro_handler();


		 } else {
		 	steam_activation_f_debug("User already activated");
		 	//User already activated

		 	steam_activation_f_debug("WTF are you doing here? This is not supposed to happen: Steam set and activated, but you are here? Something is very wrong!");
		 }
	}
}

//Return if the person meets the requirements
function steam_activation_f_should_be_activated() {
	steam_activation_f_debug("Function: should_be_activated()");

	global $mybb;

	if($mybb->user["usergroup"] != $mybb->settings["steam_activation_non_activated_group"]) {
		//Something is wrong, you are already activated, let's not activate you again
		steam_activation_f_debug("Already activated, returning false");

		return false;
	} else if(!isset($mybb->user['steam_activation_steamid'])) {
		//No Steam set
		steam_activation_f_debug("Steam not yet set, returning false");

		return false;
	} else if($mybb->settings["steam_activation_introduction"] == "force" && 
	$mybb->user['threadnum'] == "0") {
		//Should have posted, but hasn't
		steam_activation_f_debug("Introduction = Force, but no post, returning false");

		return false;
	} else {
		//All good to go
		steam_activation_f_debug("No problem found, returning true");

		return true;
	}
}

//Activate user
function steam_activation_f_activate_user() {
	steam_activation_f_debug("Fuction: activate_user()");
	global $mybb, $db;

	//Set usergroup to the activated group defined in settings
    $db->update_query("users", array("usergroup" => $mybb->settings["steam_activation_activated_group"]), "uid=".$mybb->user['uid']);
}

//Handle the steam login page
function steam_activation_f_steam_handler() {
	steam_activation_f_debug("Function: steam_handler()");
	global $mybb;

	if(steam_activation_f_steam_online()) {
		steam_activation_f_debug("Steam is online, we can continue");
		if(file_exists(__DIR__."/openid.php")) {
			steam_activation_f_debug("openid.php exists, including it");
		
			include_once(__DIR__."/openid.php");
		
			$openID = new LightOpenID($_SERVER["SERVER_NAME"]);
			$openID->identity = "http://steamcommunity.com/openid";

			$requestUriStripped = steam_activation_f_get_strippeduri();
			steam_activation_f_debug("Stripped RequestURI for OpenID: ".$requestUriStripped);
			$openID->returnUrl = $openID->realm.$requestUriStripped;

			steam_activation_f_debug("Variable OpenID->mode has value: ".$openID->mode);
			if($openID->mode == "cancel") {
				steam_activation_f_debug("Calcel detected");

				steam_activation_f_steam_display_page($openID, array("error"=>"loginCancelled"));

			} else if($openID->mode) {
				steam_activation_f_debug("Some mode detected, probably logged in");

				if($openID->validate()) {
					steam_activation_f_debug("Validation succes: logged in");

					$steamID = basename($openID->identity); 
					steam_activation_f_debug("Logged in with steamID: ".$steamID);

					$steamCheckInfo = steam_activation_f_check_duplicate($steamID);

					if(isset($steamCheckInfo["error"])) {
						steam_activation_f_debug("Error already thrown, no need to run the custom checks");
					} else {
						steam_activation_f_debug("No error thrown yet, let's run the custom checks");
						
						$steamCheckInfo = steam_activation_f_check_uid($mybb->user["uid"], $steamID);
					}

					//Evaluate the errors
					if(isset($steamCheckInfo["error"])) {
						steam_activation_f_debug("Stopping since error: ".$steamCheckInfo["error"]." has been thrown");
						//Error was thrown, ohh oh
						
						steam_activation_f_steam_display_page($openID, $steamCheckInfo);

					} else {
						steam_activation_f_debug("Since no error has been thrown, we move on");
						//No error has been thrown, move on

						//Enter their SteamID to the database
						steam_activation_f_setsteam($steamID);
						
						steam_activation_f_debug("Since user has now got a steam listed, moving on to intruduction handling");
						steam_activation_f_intro_handler();
					}
				} else {
					steam_activation_f_debug("Validation failed: login has failed");

					steam_activation_f_steam_display_page($openID, array("error"=>"loginFailed"));
				}
			} else {
				steam_activation_f_debug("No mode detected, nothing regarding a Steam login found");
				//No login info yet, display the page

				steam_activation_f_steam_display_page($openID);
			}
		} else {
			steam_activation_f_debug("ERROR: Wanted to include openid.php, but it didn't exist");
		}
	} else {
		steam_activation_f_debug("Steam is OFFLINE!!!");
		steam_activation_f_steam_display_page("", array("error" => "steamDown") );
		//Steam is offline!
	}
}

//Handle the introduction page
function steam_activation_f_intro_handler() {
	steam_activation_f_debug("Function: intro_handler()");
	global $mybb;

	if($mybb->user["usergroup"] != $mybb->settings["steam_activation_non_activated_group"]) {
		steam_activation_f_debug("User already activated, no need for an introduction, cya!");
		//Already activataed, no need for introduction

	} else {
		steam_activation_f_debug("User not yet activated");
		//User not yet activated

		//Activate the user if we can already do so, but still go on since
		// the setting for introduction might be on optional
		if(steam_activation_f_should_be_activated()) {
			steam_activation_f_activate_user();
		}

		steam_activation_f_debug("Setting: introduction has value: ".$mybb->settings["steam_activation_introduction"]);
		if($mybb->settings["steam_activation_introduction"] == "off") {
			steam_activation_f_debug("Introductions are turned off, cya!");
			steam_activation_f_debug("However, I should still first refresh the page to make MyBB work and also get rid of the ugly link.");

			//header("Location: ".steam_activation_f_get_strippeduri());
			steam_activation_f_refresh();
		} else {
			steam_activation_f_debug("Intoductions are turned on, lets go!");

			//Display the post-introduction page
			steam_activation_f_intro_display_page();

		}
	}
}

//Display the Steam-login page
function steam_activation_f_steam_display_page($openID, $errorInfo) {
	steam_activation_f_debug("Function: steam_display_page()");
	global $mybb;

	steam_activation_f_debug("I am going to convert the template to an actual page now");
	$page = $mybb->settings["steam_activation_template_steam"];

	$page = str_replace("{steamloginbutton}", steam_activation_f_get_steamloginbutton($openID), $page);
	$page = steam_activation_f_replace_shared_codes($page);

	//Error replacing
	if(isset($errorInfo) && isset($errorInfo["error"])) {
		steam_activation_f_debug("Error detected: ".$errorInfo["error"]);
	
		$errorTemplates = steam_activation_f_get_errortemplates();

		if(isset($errorTemplates[$errorInfo["error"]])) {
			steam_activation_f_debug("There is a template found for this error");
			
			$error = $errorTemplates[$errorInfo["error"]];

			//Replacements
			foreach($errorInfo as $code => $value) {
				$error = str_replace("{".$code."}", $value, $error);
			}

			$error = steam_activation_f_replace_shared_codes($error);

			steam_activation_f_debug("Finished building the error message, here it is: \"".htmlspecialchars($error)."\"");

			//Actually place the error message
			$page = str_replace("{".$errorInfo["error"]."}", $error, $page);

		} else {
			steam_activation_f_debug("No template found for this error");

		}
	} else {
		steam_activation_f_debug("No error detected");
		//No errors

	}

	//Remove all remaining {codes}
	$page = preg_replace("/\{.*\}/sU", "", $page);

	steam_activation_f_debug("Time to paste the steam page, here we go");
	echo($page);

	//At the end of a custom page, we die to prevent the actual page from showing.
	steam_activation_f_die();
}

//Display the introductions page
function steam_activation_f_intro_display_page() {
	steam_activation_f_debug("Function: intro_display_page()");
	global $mybb;

	steam_activation_f_debug("I am going to convert the template to an actual page now");
	$page = $mybb->settings["steam_activation_template_introduction"];
	$page = str_replace("{steamid}", $mybb->user["steam_activation_steamid"], $page);
	
	if($mybb->settings["steam_activation_introduction"] === "optional") {
		steam_activation_f_debug("Intro-setting is set to optional, pasting skip-link");

		$page = str_replace("{skip}", $mybb->settings["steam_activation_template_introduction_skip"], $page);
		$page = str_replace("{skiplink}", steam_activation_f_get_requestedpage(), $page);
	} else {
		steam_activation_f_debug("Intro-setting is not set to optional, not pasting skip-link");

		$page = str_replace("{skip}", "", $page);
		$page = str_replace("{skiplink}", "", $page);
	}
	
	$page = str_replace("{formstart}", steam_activation_f_get_formstart(), $page);
	$page = str_replace("{editorstart}", "<textarea name=\"message\" id=\"message\" rows=\"10\" cols=\"60\" tabindex=\"1\">", $page);
	$page = str_replace("{editorend}", "</textarea>", $page);
	$page = str_replace("{formend}", "</form>", $page);


	$page = steam_activation_f_replace_shared_codes($page);

	echo($page);

	//At the end of a custom page, we die to prevent the actual page from showing.
	steam_activation_f_die();
}

function steam_activation_f_replace_shared_codes($page) {
	steam_activation_f_debug("Function replace_shared_codes()");
	global $mybb;

	$page = str_replace("{userid}", $mybb->user["uid"], $page);
	$page = str_replace("{username}", $mybb->user["username"], $page);
	$page = str_replace("{userid}", $mybb->user["uid"], $page);
	$page = str_replace("{logoutlink}", steam_activation_f_get_logoutlink(), $page);
	$page = str_replace("{requestedpage}", steam_activation_f_get_requestedpage(), $page);

	return $page;
}

//Kill the remainder of the page
function steam_activation_f_die() {
	steam_activation_f_debug("Function: die()");

	steam_activation_f_debug("I am dying, most likely to prevent the usual mybb page from showing");
	die;
}

//Put the SteamID in the Database
function steam_activation_f_setsteam($steamID) {
	steam_activation_f_debug("Function: setsteam()");
	global $mybb, $db;

	steam_activation_f_debug("Setting your steamid to ".$steamID);
	$db->update_query("users", array("steam_activation_steamid" => $steamID), "uid=".$mybb->user['uid']);

	//Update the userfield
	steam_activation_f_setuserfield($steamID);
}

//Set the users userfield to the inserted steamID
function steam_activation_f_setuserfield($steamID) {
	steam_activation_f_debug("Function: setuserfield()");
	global $mybb, $db;

	$link = "http://steamcommunity.com/profiles/".$steamID;
	$fieldValue = "[b][url=".$link."]".$steamID."[/url][/b]";

	steam_activation_f_debug("Changing the userfield to: ".$fieldValue);
	$db->update_query("userfields", array("fid".$mybb->settings["steam_activation_userfieldID"] => $fieldValue), "ufid=".$mybb->user['uid']);
}

//Check for duplicate steamaccounts
function steam_activation_f_check_duplicate($steamID) {
	steam_activation_f_debug("Function: check_duplicate()");
	global $mybb, $db;

	//Check the setting if duplicates are allowed
	if($mybb->settings["steam_activation_allow_duplicate"]) {
		steam_activation_f_debug("Duplicates allowed");
		
		return array();
	} else {
		steam_activation_f_debug("Duplicates not allowed, checking now");

		//Query for any other users with same steamid
		$result = $db->simple_select("users", "*", "steam_activation_steamid=".$steamID);
		if($db->num_rows($result) == 0) {
			steam_activation_f_debug("No duplicates found for ".$steamID);

			return array();
		} else {
			steam_activation_f_debug("Duplicate found");

			$duplicateInfo = $db->fetch_array($result);
			steam_activation_f_debug("ID: ".$duplicateInfo["uid"]." - Name: ".$duplicateInfo["username"]);

			//Send the returndata
			$returnarray = array(
				"error" => "duplicate",
				"duplicateUID" => $duplicateInfo["uid"],
				"duplicateUsername" => $duplicateInfo["username"]
			);

			return $returnarray;
		}
	}
}

//Get all custom check functions
function steam_activation_f_get_customchecks() {
	steam_activation_f_debug("Function: get_customchecks()");
	global $mybb;
	
	$customChecksString = $mybb->settings["steam_activation_custom_checks"];
	if(isset($customChecksString) && $customChecksString !== "") {
		steam_activation_f_debug("Custom checks found");

		$customChecks = explode(",", $customChecksString);
		steam_activation_f_debug(count($customChecks)." custom checks found");

		return $customChecks;
	} else {
		steam_activation_f_debug("No custom checks set");

		return array();
	}
}

//Get all errorTemplates in array[error]=template
function steam_activation_f_get_errortemplates() {
	steam_activation_f_debug("Function: get_errortemplates()");
	global $mybb;

	$templates = $mybb->settings["steam_activation_template_steam_errors"];

	if(isset($templates) && $templates !== "") {
		steam_activation_f_debug("Error templates were detected, let's split them");
		
		$errors = explode(";", $templates);
		steam_activation_f_debug("Found ".count($errors)." error templates");

		$errorTemplates = array();
		foreach($errors as $error) {
			steam_activation_f_debug("Going over error string \"".htmlspecialchars($error)."\"");
			$info = explode("}", $error, 2);
			$errorName = explode("{", $info[0])[1];
			$errorTemplate = $info[1];
			$errorTemplates[$errorName] = $errorTemplate;
			steam_activation_f_debug("Found errorName=".$errorName." and errorTemplate=".htmlspecialchars($errorTemplate));
		}

		return $errorTemplates;
	} else {
		steam_activation_f_debug("No error templates found");

		return array();
	}
}

//Get the exact link the user has in his url bar
function steam_activation_f_get_requestedpage() {
	steam_activation_f_debug("Function: get_requestedpage()");

	$requestedpage = "http".(isset($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443 ? 's' : '')."://".$_SERVER['HTTP_HOST'].steam_activation_f_get_strippeduri();
	steam_activation_f_debug("Requested page: ".$requestedpage);
	return $requestedpage;
}

//Strip the Steam Auth stuff off of the link
function steam_activation_f_get_strippeduri() {
	steam_activation_f_debug("Function: get_strippeduri()");

	return explode("&openid.ns=", explode("?openid.ns=", $_SERVER['REQUEST_URI'])[0])[0];
}

//Check if a user ID is allowed
function steam_activation_f_check_uid($uid, $steamID) {
	steam_activation_f_debug("Function: check_uid()");
	steam_activation_f_debug("Selected uid: ".$uid);
	global $db;

	if(!isset($steamID)) {
		steam_activation_f_debug("No steamID given, grabbing the one from the database");

		//Query for user with uid $uid
		$result = $db->simple_select("users", "*", "uid=".$uid);

		if($db->num_rows($result) == 0) {
			steam_activation_f_debug("User does not exist, setting $steamID to 00000000000000000");
			$steamID = "00000000000000000";

		} else {
			steam_activation_f_debug("User found in the database");

			$userInfo = $db->fetch_array($result);
			$steamID = $userInfo["steam_activation_steamid"];

			steam_activation_f_debug("Found SteamID ".$steamID);

			steam_activation_f_debug("Found steamID ".$steamID);
		}
	} else {
		steam_activation_f_debug("A steamID was given to check against: ".$steamID);
		//Steam detected
	}

	$customChecks = steam_activation_f_get_customchecks();

	steam_activation_f_debug("Going to execute all checks");
	foreach($customChecks as $checkFunction) {
		//Execute each check-function
		
		//Include file
		if(file_exists(__DIR__."/".$checkFunction.".php")) {
			steam_activation_f_debug($checkFunction.".php exists, including it");

			include_once(__DIR__."/".$checkFunction.".php");

			//Execute
			if(function_exists($checkFunction)) {
				steam_activation_f_debug("The function ".$checkFunction."() exists, executing it");
				
				$checkInfo = $checkFunction($uid, $steamID);

				//Check if error thrown
				if(isset($checkInfo["error"])) {
					steam_activation_f_debug("Check ".$checkFunction." threw an error: ".$checkInfo["error"]);
					//Error thrown

					return($checkInfo);
				} else {
					steam_activation_f_debug("Check ".$checkFunction." did not throw an error");
					//No error, all good, time to loopadieloop
				}
			} else {
				steam_activation_f_debug("ERROR: The function ".$checkFunction."() does not exist");
			}
		} else {
			steam_activation_f_debug("ERROR: Wanted to imlement ".$checkFunction.".php but it doesn't exist");
		}
	}

	steam_activation_f_debug("No errors were found, returning an empty array.");
	//No errors, return empty array
	return array();
}

//Get the logout link for the current user
function steam_activation_f_get_logoutlink() {
	steam_activation_f_debug("Function: get_logoutlink()");
	global $mybb;

	$logoutlink = $mybb->settings['bburl']."/member.php?action=logout&logoutkey=".$mybb->user['logoutkey'];
	steam_activation_f_debug("Logout link: ".$logoutlink);
	return $logoutlink;
}

//Get the login-with-steam image-button with the right link
function steam_activation_f_get_steamloginbutton($openID) {
	steam_activation_f_debug("Function: get_steamloginbutton()");

	return "<a href=\"".steam_activation_f_get_steamloginlink($openID)."\"><img src=\"https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_02.png\" /></a>";
}

//Get the steam login link
function steam_activation_f_get_steamloginlink($openID) {
	steam_activation_f_debug("Function: get_steamloginlink()");

	if(steam_activation_f_steam_online()) {
		steam_activation_f_debug("Steam online, returning the button");
	 	return $openID->authURL();
	} else {
		steam_activation_f_debug("Steam offline, linking to steamstat.us");

		return "http://steamstat.us";
	}	
}

//Get the string for the form-start on the introcutions page
function steam_activation_f_get_formstart() {
	steam_activation_f_debug("Function: get_formstart()");
	global $mybb;

	$subject = $mybb->settings["steam_activation_template_introduction_subject"];
	$subject = steam_activation_f_replace_shared_codes($subject);
	$string = 
		"<form action=\"".steam_activation_f_get_postlink()."\" method=\"post\" enctype=\"multipart/form-data\" name=\"input\">".
		"<input type=\"hidden\" name=\"my_post_key\" value=\"".$mybb->post_code."\" />".
		"<input type=\"hidden\" name=\"subject\" value=\"".$subject."\" />".
		"<input type=\"hidden\" name=\"action\" value=\"do_newthread\" />".
		"<input type=\"hidden\" name=\"postoptions[signature]\" value=\"1\" />".
		"<input type=\"hidden\" name=\"posthash\" value=\"".md5($mybb->user['uid'].random_str())."\" />";

	return $string;
}

//Get the link to post an introduction
function steam_activation_f_get_postlink() {
	steam_activation_f_debug("Function: get_postlink()");
	global $mybb;

	$link = $mybb->settings['bburl']."/newthread.php?fid=".$mybb->settings['steam_activation_introduction_forum']."&processed=1";

	steam_activation_f_debug("Postlink: ".$link);
	return $link;
}

//Check each user if he is allowed according to the custom checks and ban if not
function steam_activation_f_bancheck_allusers() {
	steam_activation_f_debug("Function: bancheck_allusers()");
	global $db;

	$result = $db->simple_select("users", "*");

	steam_activation_f_debug("Found ".$db->num_rows($result)." users to check");

	steam_activation_f_debug("Going to loop over all of them now. Prepare for a shit load of messages!");
	steam_activation_f_debug("");
	steam_activation_f_debug("");

	while($userInfo = $db->fetch_array($result)) {
		steam_activation_f_bancheck_user($userInfo["uid"], $userInfo["steam_activation_steamid"]);
		steam_activation_f_debug("");
	}

	steam_activation_f_debug("");
	steam_activation_f_debug("LoopEnd: Finished checking all users");
}

//Check the current user if he is allowed according to the custom checks and ban if not
function steam_activation_f_bancheck_currentuser() {
	steam_activation_f_debug("Function: bancheck_currentuser()");
	global $mybb;

	steam_activation_f_bancheck_user($mybb->user["uid"], $mybb->user["steam_activation_steamid"]);
}

//Check the user with uid if he is allowed according to the custom checks and ban if not
function steam_activation_f_bancheck_user($checkuid, $checkSteamID) {
	steam_activation_f_debug("Function: bancheck_user()");

	$banInfo = steam_activation_f_check_uid($checkuid, $checkSteamID);

	if(isset($banInfo["error"])) {
		steam_activation_f_debug("<b>An error was thrown: ".$banInfo["error"]."</b>");
		//An error was thrown. Lets do something about it!

		if(isset($banInfo["banlength"])) {
			steam_activation_f_debug("Length detected: ".$banInfo["banlength"]);
			
			$banlength = $banInfo["banlength"];
		} else {
			//Default ban length
			$banlength = 15780000; //6 Months

			steam_activation_f_debug("No ban length detected, using default: ".$banlength);
		}

		if(isset($banInfo["banreason"])) {
			steam_activation_f_debug("Reason detected: ".$banInfo["banreason"]);
			
			$banreason = $banInfo["banreason"];
		} else {
			//Default ban length
			$banreason = "Automatic ban by a custom plugin of the steam_activation plugin. It threw the following error: ".$banInfo["error"].".";

			steam_activation_f_debug("No ban reason detected, using default: ".$banreason);
		}

		steam_activation_f_banuser($checkuid, $banlength, $banreason);		
	} else {
		steam_activation_f_debug("No error found. ".$checkuid." is all good!");
		
	}
}

//Ban a certain user off of the forums
function steam_activation_f_banuser($uid, $length, $reason) {
	steam_activation_f_debug("Function: banuser()");
	global $db, $mybb;

	if($length == 0) {
		steam_activation_f_debug("Length is 0, not banning");

	} else {
		steam_activation_f_debug("Length is not 0, so we should ban");
		
		//Query for user with uid $uid
		$result = $db->simple_select("users", "*", "uid=".$uid);

		if($db->num_rows($result) == 0) {
			steam_activation_f_debug("<b>ERROR</b>User does not exist. This should not happen!");

		} else {
			steam_activation_f_debug("User found in the database");

			$userInfo = $db->fetch_array($result);

			if($userInfo["usergroup"] == $mybb->settings["steam_activation_banned_group"]) { 
				steam_activation_f_debug("User is already banned, not double-banning");

			} else {
				steam_activation_f_debug("User not already banned, let's ban this fucker!");

				if($length == -1) {
					steam_activation_f_debug("Permament ban detected");
					$bantime = "---";
					$lifted = 0;
				} else {
					steam_activation_f_debug("Ban is not a permaban. Building bantime and lifted db-entries now");
				

					$secperyear = 31536000;
					$secspermonth = 2630000;
					$secsperday = 86400;

					$timeconstruct = $length;
					$years = floor($timeconstruct / $secsperyear);
					$timeconstruct = $timeconstruct - $years * $secsperyear; 
					$months = floor($timeconstruct / $secspermonth);
					$timeconstruct = $timeconstruct - $months * $secspermonth;
					$days = floor($timeconstruct / $secsperday);


					$bantime = $years."-".$months."-".$days;

					$lifted = time()+$length;
				}

				$banInfo = array(
					"uid" => $uid,
					"gid" => $mybb->settings["steam_activation_banned_group"],
					"oldgroup" => $userInfo["usergroup"],
					"olddisplaygroup" => $userInfo["displaygroup"],
					"admin" => 0,
					"dateline" => time(),
					"bantime" => $bantime,
					"lifted" => $lifted,
					"reason" => $reason
				);

				//Insert baninfo into the "banned" table
				$db->insert_query("banned", $banInfo);

				//Update usergroup
				$db->update_query("users", array("usergroup" => $mybb->settings["steam_activation_banned_group"]), "uid=".$uid);
			
			}
		}
	}
}

//Check if the steam openid page is online 
function steam_activation_f_steam_online() {
	steam_activation_f_debug("Function: steam_online()");
	global $steam_activation_steam_online, $mybb;

	if(isset($steam_activation_steam_online)) {
		steam_activation_f_debug("Already checked this, not doing it again");
		
	} else {
		steam_activation_f_debug("Not checked yet, going to check it now");
		
		$host = "https://steamcommunity.com/openid";

		if($mybb->settings["steam_activation_curl"]) {
			steam_activation_f_debug("We should use CURL");

			// Initialize curl
			$curlInit = curl_init($host);
			curl_setopt($curlInit, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($curlInit, CURLOPT_HEADER, true);
			curl_setopt($curlInit, CURLOPT_NOBODY, true);
			curl_setopt($curlInit, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curlInit, CURLOPT_SSL_VERIFYPEER, false);
			
			// Get answer
			$response = curl_exec($curlInit);

			curl_close($curlInit);

			if($response){ 
				steam_activation_f_debug("Steam ONLINE");

				$steam_activation_steam_online = true;
			} else { 
				steam_activation_f_debug("Steam OFFLINE!!");

				$steam_activation_steam_online = false;
			}
		} else {
			steam_activation_f_debug("We should NOT use CURL");

			$header = get_headers($host, 1)[0];
			steam_activation_f_debug("Header: ".$header);

			if($header === "HTTP/1.0 200 OK") {
				steam_activation_f_debug("Steam Online");

				$steam_activation_steam_online = true;
			} else {
				steam_activation_f_debug("Steam Offline!!");

				$steam_activation_steam_online = false;
			}
		}
	}
	
	return $steam_activation_steam_online;
}

//Update profilefield settings
function steam_activation_f_profilefieldsettings_update() {
	steam_activation_f_debug("Funtion: profilefieldssettings_update()");
	global $db, $mybb, $cache;

	$db->update_query("profilefields", array("profile" => $mybb->settings["steam_activation_show_profile"], "postbit" => $mybb->settings["steam_activation_show_postbit"]), "fid=".$mybb->settings["steam_activation_userfieldID"]);

	steam_activation_f_debug("Updating profilefields cache");
	$cache->update_profilefields();
}

//Refresh the current page
function steam_activation_f_refresh() {
	steam_activation_f_debug("Function: refresh()");

	header("Refresh:0");
	echo("<br><br>Your page should now refresh. If you don't want to wait, refresh this page yourself. The page might take upto 30 seconds to load.<br>");
	steam_activation_f_die();
}

?>
