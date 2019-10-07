<?php
return [
    'ctrl' => [
        'title' => 'DataHander Test Element',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'languageField'            => 'sys_language_uid',
        'transOrigPointerField'    => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'prependAtCopy' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.prependAtCopy',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'versioningWS' => true,
        'origUid' => 't3_origuid',
    ],
    'interface' => [
        'showRecordFieldList' => 'sys_language_uid,l10n_parent,l10n_diffsource,hidden,title'
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label'  => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => [
                'type'                => 'select',
                'renderType' => 'selectSingle',
                'foreign_table'       => 'sys_language',
                'items' => [
                    ['LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.allLanguages', -1],
                    ['LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.default_value', 0]
                ],
                'default' => 0
            ]
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'exclude'     => 1,
            'label'       => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config'      => [
                'type'  => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['', 0],
                ],
                'foreign_table'       => 'tx_irretutorial_1nff_hotel',
                'foreign_table_where' => 'AND tx_irretutorial_1nff_hotel.pid=###CURRENT_PID### AND tx_irretutorial_1nff_hotel.sys_language_uid IN (-1,0)',
                'default' => 0
            ]
        ],
        'l10n_diffsource' => [
            'config' => [
                'type' => 'passthrough',
                'default' => ''
            ]
        ],
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0
            ]
        ],
        'title' => [
            'exclude' => true,
            'l10n_mode' => 'prefixLangTitle',
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'required',
            ]
        ],
    ],
    'types' => [
        '0' => [
            'showitem' =>
                '--div--;LLL:EXT:irre_tutorial/Resources/Private/Language/locallang_db.xml:tabs.general, title,' .
                '--div--;LLL:EXT:irre_tutorial/Resources/Private/Language/locallang_db.xml:tabs.visibility, sys_language_uid, l10n_parent, l10n_diffsource, hidden'
        ]
    ],
    'palettes' => [
        '1' => [
            'showitem' => ''
        ]
    ]
];
