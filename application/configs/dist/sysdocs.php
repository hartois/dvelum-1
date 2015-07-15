<?php
return array(
    'gen_version'=>4,
	'versions'=>array(
       '0.9.5'=>3,
       '1.0.0'=>4
    ),
    'default_languge'=> 'ru',
    'default_version' => '0.9.5',
    'locations'=>array(
        './application/controllers',
        './application/library',
        './application/models',
		'./dvelum/app',
        './dvelum/library',
	),
    'exceptions'=>array(
        './dvelum/library/Spreadsheet'
    ),
    'hid_generator' => array(
        'adapter' => 'Sysdocs_Historyid',
    ),
    'fields' => array(
      'sysdocs_class' => array(
          'description'
      ),
      'sysdocs_class_method' => array(
          'description',
          'returnType'
      ),
      'sysdocs_class_method_param' => array(
          'description'
      ),
      'sysdocs_class_property' => array(
          'description',
          'type'
      ),
    )
);