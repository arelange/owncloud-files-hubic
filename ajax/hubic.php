<?php

OCP\JSON::checkAppEnabled('files_external');
OCP\JSON::checkAppEnabled('files_hubic');
OCP\JSON::checkLoggedIn();
OCP\JSON::callCheck();
$l = OC_L10N::get('files_external');

if (isset($_POST['client_id']) && isset($_POST['client_secret']) && isset($_POST['redirect'])) {
	if (isset($_POST['step'])) {
		$step = $_POST['step'];
		if ($step == 1) {
			try {
				$authUrl = 'https://api.hubic.com/oauth/auth/'.
					'?client_id='.urlencode($_POST['client_id']).
					'&redirect_uri='.urlencode($_POST['redirect']).
					'&scope=credentials.r'.
					'&response_type=code';
				OCP\JSON::success(array('data' => array(
					'url' => $authUrl
				)));
			} catch (Exception $exception) {
				OCP\JSON::error(array('data' => array(
					'message' => $l->t('Step 1 failed. Exception: %s', array($exception->getMessage()))
				)));
			}
		} else if ($step == 2 && isset($_POST['code'])) {
			try {
				$hubicAuthClient = curl_init();
				curl_setopt($hubicAuthClient, CURLOPT_URL, 'https://api.hubic.com/oauth/token/');
				curl_setopt($hubicAuthClient, CURLOPT_POST, 1);
				curl_setopt($hubicAuthClient, CURLOPT_POSTFIELDS,
					'grant_type=authorization_code'.
					'&redirect_uri='.urlencode($_POST['redirect']).
					'&code='.$_POST['code']);
				curl_setopt($hubicAuthClient, CURLOPT_USERPWD, $_POST['client_id'].":".$_POST['client_secret']);
				curl_setopt($hubicAuthClient, CURLOPT_RETURNTRANSFER, TRUE);
				$token = curl_exec($hubicAuthClient);

				curl_close($hubicAuthClient);

				OCP\JSON::success(array('data' => array(
					'token' => $token
				)));
			} catch (Exception $exception) {
				OCP\JSON::error(array('data' => array(
					'message' => $l->t('Step 2 failed. Exception: %s', array($exception->getMessage()))
				)));
			}
		}
	}
}
