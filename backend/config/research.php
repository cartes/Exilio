<?php

/*
 * Controles de la investigación web (Fase 6). Viven en configuración/entorno, fuera de los
 * atributos que EXILIO-001 puede editar: ni el agente ni la carta de una misión pueden superarlos.
 */
return [
    // Interruptor general. Con false no se realiza ninguna petición web.
    'enabled' => (bool) env('RESEARCH_ENABLED', true),

    // Buscador general autoalojado (SearXNG, ver docker-compose.yml). Sin clave de API.
    'searxng_url' => env('RESEARCH_SEARXNG_URL', 'http://127.0.0.1:8888'),

    'user_agent' => 'ExilioScout/0.1 (investigacion local de solo lectura)',

    // Topes duros por misión (la carta puede pedir menos, nunca más).
    'max_pages_hard_cap' => 8,
    'max_per_host' => 2,
    'max_search_results' => 15,

    // Límites por petición.
    'timeout_seconds' => 10,
    'max_bytes' => 1_000_000,
    'max_redirects' => 3,
    'max_text_chars' => 20_000,
    'allowed_content_types' => ['text/html', 'application/xhtml+xml', 'text/plain'],

    // Solo estos puertos (la puerta rechaza cualquier otro).
    'allowed_ports' => [80, 443],

    // Dominios que nunca se visitan (se aplica también a subdominios).
    'blocked_domains' => [
        'localhost',
        'local',
        'internal',
        'lan',
        'onion',
    ],
];
