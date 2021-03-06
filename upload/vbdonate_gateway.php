<?php
/*======================================================================*\
|| #################################################################### ||
|| # ---------------------------------------------------------------- # ||
|| # Copyright ©2007-2009 Fillip Hannisdal AKA Revan/NeoRevan/Belazor # ||
|| # All Rights Reserved. 											  # ||
|| # This file may not be redistributed in whole or significant part. # ||
|| # ---------------------------------------------------------------- # ||
|| # You are not allowed to use this on your server unless the files  # ||
|| # you downloaded were done so with permission.					  # ||
|| # ---------------------------------------------------------------- # ||
|| #################################################################### ||
\*======================================================================*/

// ####################### SET PHP ENVIRONMENT ###########################
error_reporting(E_ALL & ~E_NOTICE);

// #################### DEFINE IMPORTANT CONSTANTS #######################
define('THIS_SCRIPT', 'vbdonate_gateway');
define('CSRF_PROTECTION', false);
define('SKIP_SESSIONCREATE', 1);
define('BYPASS_FORUM_DISABLED', true);
define('VBDONATE_DEBUG', false); // OZZY TODO: Set this to false (but DO NOT REMOVE) when testing is done)
define('VBDONATE_DEBUG_EMAIL', ''); // Set this to the email that should receive debug emails
// #################### PRE-CACHE TEMPLATES AND DATA ######################
// get special phrase groups
$phrasegroups = array();

// get special data templates from the datastore
$specialtemplates = array();

// pre-cache templates used by all actions
$globaltemplates = array();

// pre-cache templates used by specific actions
$actiontemplates = array();

// ######################### REQUIRE BACK-END ############################
define('VB_AREA', 'Subscriptions');
define('CWD', (($getcwd = getcwd()) ? $getcwd : '.'));
require_once('./global.php');
require_once(DIR . '/includes/functions_threadmanage.php');
require_once(DIR . '/includes/functions_databuild.php');
require_once(DIR . '/includes/functions_log_error.php');

// ########################################################################
// ######################### START MAIN SCRIPT ############################
// ########################################################################
if(isset($_GET['number']) && $_GET['Status'] == 'OK'  && isset($_GET['Authority'])){

	$id = intval($_GET['number']);
	$transaction = $db->query_first("SELECT donation.*, user.username, user.usergroupid, user.membergroupids FROM  `". TABLE_PREFIX ."dbtech_vbdonate_donations` AS donation LEFT JOIN " . TABLE_PREFIX . "user AS user ON (user.userid = donation.userid) WHERE donation.id='$id' AND donation.confirmed = '0' ");

	if (!$transaction = $db->query_first("SELECT donation.*, user.username, user.usergroupid, user.membergroupids FROM  `". TABLE_PREFIX ."dbtech_vbdonate_donations` AS donation LEFT JOIN " . TABLE_PREFIX . "user AS user ON (user.userid = donation.userid) WHERE donation.id='$id' AND donation.confirmed = '0' "))
	{
		payment_fail();
	}

	$data = array("merchant_id" => $vbulletin->options['dbtech_vbdonate_email'], "authority" => $_GET['Authority'], "amount" => intval($transaction['amount'] * 10));
	$jsonData = json_encode($data);
	$ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
	curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/json',
		'Content-Length: ' . strlen($jsonData)
	));

	$result = curl_exec($ch);
	$err = curl_error($ch);
	curl_close($ch);
	$result = json_decode($result, true);


	if ($result['data']['code'] == 100)
	{
		$db->query_write("
			UPDATE " . TABLE_PREFIX . "dbtech_vbdonate_donations
			SET 
				confirmed = '1',
				response = " . $db->sql_prepare('REFID => '. $result['data']['ref_id'] .' AU => '.$_GET['Authority']) . "
			WHERE id = " . intval($transaction['id'])
		);
		
		do
		{
			

			if (!$transaction['userid'])
			{
				// This is a guest donation
				payment_fail();
				break;
			}

			// Do some shorthands
			$targetGroup = intval($vbulletin->options['dbtech_vbdonate_donator_usergroup']);
			$groupTitle = trim($vbulletin->options['dbtech_vbdonate_usergroup_title']);


			switch ($vbulletin->options['dbtech_vbdonate_usergroup_type'])
			{
				case 1:
					// Update membergroups, displaygroupid and usertitle as needed
					$db->query_write("
						UPDATE " . TABLE_PREFIX . "user 
						SET 
							usergroupid = " . $targetGroup . 
							($vbulletin->options['dbtech_vbdonate_usergroup_display'] ? ", displaygroupid = $targetGroup" : '') . 
							($vbulletin->options['dbtech_vbdonate_usergroup_show'] ? ", usertitle = '" . $db->escape_string($groupTitle) . "'" : '') . "
						WHERE userid = '" . $transaction['userid'] . "'
					");
					// Update ranks as needed					
					if ($vbulletin->options['dbtech_vbdonate_ranks_enabled'])
					{					
						$new_rank = $vbulletin->options['dbtech_vbdonate_postbit_awards_img'];
						$db->query_write(" 
							UPDATE " . TABLE_PREFIX . "usertextfield 
							SET 
								rank = '<img src=\"images/ranks/$new_rank\" alt=\"\" border=\"\" />' 
							WHERE usertextfield.userid = '" . $transaction['userid'] . "' 
						");
					}
					break;

				case 2:

					// Update membergroups, displaygroupid and usertitle as needed
					$db->query_write("
						UPDATE " . TABLE_PREFIX . "user 
						SET 
							membergroupids = " . (!$transaction['membergroupids'] ? $targetGroup : "CONCAT(membergroupids, '," . $targetGroup . "')") . 
							($vbulletin->options['dbtech_vbdonate_usergroup_display'] ? ", displaygroupid = $targetGroup" : '') . 
							($vbulletin->options['dbtech_vbdonate_usergroup_show'] ? ", usertitle = '" . $db->escape_string($groupTitle) . "'" : '') . "
						WHERE userid = '" . $transaction['userid'] . "'
					");
					// Update ranks as needed					
					if ($vbulletin->options['dbtech_vbdonate_ranks_enabled'])
					{					
						$new_rank = $vbulletin->options['dbtech_vbdonate_postbit_awards_img'];
						$db->query_write(" 
							UPDATE " . TABLE_PREFIX . "usertextfield 
							SET 
								rank = '<img src=\"images/ranks/$new_rank\" alt=\"\" border=\"\" />' 
							WHERE usertextfield.userid = '" . $transaction['userid'] . "' 
						");	
					}				
					break;
			}
		}
		while (false);

		do
		{
			switch ($vbulletin->options['dbtech_vbdonate_dateformat'])
			{
				case 1: $dateFormat = 'd-m-y, H:i'; break;
				case 2:	$dateFormat = 'm-d-y, H:i'; break;
				default: $dateFormat = 'd-m-y, H:i'; break;
			}

			// Grab subject and message
			$subject = $vbphrase['dbtech_vbdonate_confirmed_pm_title'];
			$message = construct_phrase($vbphrase['dbtech_vbdonate_confirmed_pm_message'],
				$transaction['username'],
				vbdate($dateFormat, $transaction['dateline']),
				$vbulletin->options['dbtech_vbdonate_currency'],
				$transaction['amount'],
				$vbulletin->options['bburl'] . '/vbdonate.php?do=my_contrib_table',
				$vbulletin->options['bbtitle']
			);

			// Set admin permissions for automated PMs
			$pmperms['adminpermissions'] = 2;

			// Send pm
			$pmdm =& datamanager_init('PM', $vbulletin, ERRTYPE_ARRAY);
			$pmdm->set_info('is_automated', true); // implies overridequota
			$pmdm->set('fromuserid', 	$sender['userid']);
			$pmdm->set('fromusername', 	$sender['username']);
			$pmdm->set_recipients($transaction['username'], $pmperms, 'cc');
			$pmdm->setr('title', 		$subject);
			$pmdm->setr('message', 		$message);
			$pmdm->set('dateline', 		TIMENOW);
			$pmdm->set('showsignature', 1);
			$pmdm->set('allowsmilie', 	0);
			
			if (!$pmdm->pre_save())
			{
				// We had errors
				payment_fail();
				break;
			}
			else
			{
				// No errors, yay
				$pmdm->save();
				unset($pmdm);
			}
		}
		while (false);
	
		do
		{
			if (!$transaction['userid'])
			{
				// This is a guest donation
				payment_fail();
				break;
			}

			switch ($vbulletin->options['dbtech_vbdonate_dateformat'])
			{
				case 1: $dateFormat = 'd-m-y, H:i'; break;
				case 2:	$dateFormat = 'm-d-y, H:i'; break;
				default: $dateFormat = 'd-m-y, H:i'; break;
			}

			// Grab subject and message
			$subject = construct_phrase($vbphrase['dbtech_vbdonate_auto_conf_staff_pm_title'], $transaction['username']);
			$message = construct_phrase($vbphrase['dbtech_vbdonate_auto_conf_staff_pm_message'],
				$transaction['username'], 
				$vbulletin->options['bburl'].'/vbdonate.php?do=contrib_table'
			);

			// Set admin permissions for automated PMs
			$pmperms['adminpermissions'] = 2;

			$recipients = explode(',', $vbulletin->options['dbtech_vbdonate_pm_receivers']);
			foreach ($recipients as $recipient)
			{
				if (!$recipient = fetch_userinfo($recipient))
				{
					// This staff member doesn't exist anymore
					break;
				}

				// Send pm
				$pmdm =& datamanager_init('PM', $vbulletin, ERRTYPE_ARRAY);
					$pmdm->set_info('is_automated', true); // implies overridequota
					$pmdm->set('fromuserid', 	$transaction['userid']);
					$pmdm->set('fromusername', 	$transaction['username']);
					$pmdm->set_recipients($recipient['username'], $pmperms, 'cc');
					$pmdm->setr('title', 		$subject);
					$pmdm->setr('message', 		$message);
					$pmdm->set('dateline', 		TIMENOW);
					$pmdm->set('showsignature', 1);
					$pmdm->set('allowsmilie', 	0);
				if (!$pmdm->pre_save())
				{
					// We had errors
					payment_fail();
					continue;
				} else {
					// No errors, yay
					$pmdm->save();
					unset($pmdm);
				}
			}
		}
		while (false);

		//UPDATE USER AWARDS
		$db->query_write("UPDATE " . TABLE_PREFIX . "userfield SET dbtech_vbdonations_awards = '1' WHERE userid = '" . $transaction['userid'] . "'");

		// Handled
		print_standard_redirect('hamyar_zarinpal_success', true, true);  

	} else {
		echo 'ERR:'. $err;
		payment_fail();
	}
} else {
	payment_fail();
}
function payment_fail(){
	print_standard_redirect('hamyar_zarinpal_fail', true, true); 
	exit; 
}
/*=======================================================================*\
|| ##################################################################### ||
|| # Created: 17:29, Sat Dec 27th 2008                                 # ||
|| # SVN: $RCSfile: vbecommerce.php,v $ - $Revision: $WCREV$ $
|| ##################################################################### ||
\*=======================================================================*/
?>
