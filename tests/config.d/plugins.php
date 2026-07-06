<?php

// Habilita o plugin MapasBlame na suíte de testes do repositório principal (tests/docker-compose.yml),
// sem precisar editar tests/config.d/ do core — montado via tests/docker-compose.yml deste plugin.
// "zz-mapasblame.d" ordena depois de "config.d" no glob de src/conf/config.php, então o array 'plugins'
// abaixo substitui por completo o do core (array_merge não funde arrays aninhados) — por isso repete a
// lista base e só adiciona "MapasBlame" ao final.
return [
    'plugins' => [
        'MultipleLocalAuth',
        'AdminLoginAsUser',
        'RecreatePCacheOnLogin',
        'SpamDetector',
        'MapasBlame',
    ]
];
