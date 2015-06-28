<?php
/**
 * Copyright (c) 2014, 2015 Alexandre Relange <alexandre@relange.org>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */
$l = \OC::$server->getL10N('files_hubic');

OC::$CLASSPATH['OC\Files\Storage\Hubic'] = 'files_hubic/lib/hubic.php';

\OCA\Files\App::getNavigationManager()->add([
	"id" => 'hubicstoragemounts',
	"appname" => 'files_hubic',
	"script" => 'list.php',
	"order" => 30,
	"name" => $l->t('Hubic External storage')
]);

OC_Mount_Config::registerBackend('\OC\Files\Storage\Hubic', [
	'backend' => (string)$l->t('Hubic'),
	'priority' => 100,
	'configuration' => [
		'configured' => '#configured',
		'client_id' => (string)$l->t('Client ID'),
		'client_secret' => '*'.$l->t('Client secret'),
		'hubic_token' => '#hubic token',
		'swift_token' => '#swift token'
	],
	'custom' => '../../files_hubic/js/hubic',
	'has_dependencies' => true,
	]);

