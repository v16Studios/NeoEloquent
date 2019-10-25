<?php

return [

    'default' => 'default',

    'connections' => [
        'neo4j' => [
            'driver'   => 'neo4j',
            'host'     => 'instance0',
            'port'     => 7474,
            'user'     => 'neo4j',
            'password' => 'dev',
        ],

        'default' => [
            'driver'   => 'neo4j',
            'host'     => 'instance0',
            'port'     => 7474,
            'user'     => '',
            'password' => '',
        ],
    ],
];
