<?php
/**
 * Copyright (c) 2015 Alexandre Relange <alexandre@relange.org>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */
/** @var $this OC\Route\Router */

$this->create('files_hubic_ajax', 'ajax/hubic.php')
	->actionInclude('files_hubic/ajax/hubic.php');

