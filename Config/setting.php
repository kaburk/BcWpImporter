<?php

/**
 * [Config] BcWpImporter
 *
 */
define('LOG_BCWPIMPORTER', 'bc_wp_importer');

CakeLog::config('bc_wp_importer', array(
	'engine' => 'FileLog',
	'types' => array('bc_wp_importer'),
	'file' => 'bc_wp_importer',
));

/**
 * システムナビ
 */
$config['BcApp.adminNavigation'] = [
	'Plugins' => [
		'menus' => [
			'BcImporter' => [
				'title' => __d('baser', 'データインポート'),
				'url' => [
					'admin' => true,
					'plugin' => 'bc_wp_importer',
					'controller' => 'bc_wp_importers',
					'action' => 'index'
				]
			],
		]
	],
];

$config['BcApp.adminNavi.BcImporter'] = [
	'name' => __d('baser', 'WordPressデータ インポートプラグイン'),
	'contents' => [
		[
			'name' => __d('baser', 'データインポート'),
			'url' => [
				'admin' => true,
				'plugin' => 'bc_wp_importer',
				'controller' => 'bc_wp_importers',
				'action' => 'index',
			],
		],
	],
];
