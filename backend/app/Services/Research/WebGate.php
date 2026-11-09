<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Http;

/**
 * Única puerta de salida a la web abierta para los scouts (Fase 6). Solo lectura.
 *
 * Controles (todos desde config/research.php; ni el agente ni la carta de misión los modifican):
 * - solo http/https en puertos permitidos, sin credenciales en la URL;
 * - anti-SSRF: rechaza IPs privadas/loopback/reservadas, con la IP validada fijada para la conexión
 *   y revalidación en cada redirección (manuales, con tope);
 * - dominios bloqueados, robots.txt, tipos de contenido permitidos y tamaño/tiempo máximos;
 * - sin cookies, sin JavaScript, sin formularios: solo GET.
 */
class WebGate
{
    /** @var array<string, array<string, list<string>>|null> reglas robots por origen */
    private array $robotsCache = [];

    /** @param (\Closure(string): list<string>)|null $resolver host => lista de IPs (inyectable en tests) */
    public function __construct(private ?\Closure $resolver = null)
    {
        $this->resolver ??= fn (string $host): array => (array) (gethostbynamel($host) ?: []);
    }

    /**
     * Valida una URL sin hacer peticiones.
     *
     * @return array{scheme: string, host: string, port: int, ips: list<string>, path: string}
     *
     * @throws WebGateException
     */
    public function assertAllowed(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new WebGateException('URL no válida.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new WebGateException("Esquema no permitido: {$scheme}.");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebGateException('Las URLs con credenciales no están permitidas.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, (array) config('research.allowed_ports'), true)) {
            throw new WebGateException("Puerto no permitido: {$port}.");
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $host = trim($host, '[]');

        foreach ((array) config('research.blocked_domains') as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                throw new WebGateException("Dominio bloqueado: {$host}.");
            }
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isIp && !str_contains($host, '.')) {
            throw new WebGateException("Host de una sola etiqueta no permitido: {$host}.");
        }

        $ips = $isIp ? [$host] : ($this->resolver)($host);
        if (empty($ips)) {
            throw new WebGateException("No se pudo resolver el host: {$host}.");
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new WebGateException("El host {$host} apunta a una dirección no pública ({$ip}).");
            }
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'ips' => array_values($ips),
            'path' => ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''),
        ];
    }

    /**
     * Descarga una página respetando todos los controles.
     *
     * @return array{url: string, status: int, content_type: string, body: string}
     *
     * @throws WebGateException
     */
    public function fetch(string $url): array
    {
        if (!config('research.enabled')) {
            throw new WebGateException('La investigación web está desactivada (RESEARCH_ENABLED=false).');
        }

        $current = $url;
        $maxRedirects = (int) config('research.max_redirects');

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $info = $this->assertAllowed($current);

            if (!$this->robotsAllows($info)) {
                throw new WebGateException('robots.txt no permite acceder a esta ruta.');
            }

            $response = $this->request($current, $info);
            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if ($location === '') {
                    throw new WebGateException("Redirección HTTP {$status} sin destino.");
                }
                $current = $this->absolutize($current, $location);
                continue;
            }

            $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            if (!in_array($contentType, (array) config('research.allowed_content_types'), true)) {
                throw new WebGateException("Tipo de contenido no permitido: " . ($contentType ?: 'desconocido') . '.');
            }

            return [
                'url' => $current,
                'status' => $status,
                'content_type' => $contentType,
                'body' => $this->readLimited($response),
            ];
        }

        throw new WebGateException("Demasiadas redirecciones (máx. {$maxRedirects}).");
    }

    /**
     * @param array{scheme: string, host: string, port: int, ips: list<string>, path: string} $info
     */
    private function request(string $url, array $info): \Illuminate\Http\Client\Response
    {
        // No se usa la opción 'stream': activaría el manejador de streams de Guzzle, que ignora las
        // opciones de cURL (necesarias para fijar la IP validada). El tamaño se limita con 'progress'.
        $abortAt = (int) config('research.max_bytes') * 3;
        $options = [
            'allow_redirects' => false,
            'progress' => function ($downloadTotal, $downloaded) use ($abortAt): void {
                if ($downloaded > $abortAt || $downloadTotal > $abortAt) {
                    throw new WebGateException('La página supera el tamaño máximo permitido.');
                }
            },
        ];
        // Fija la IP ya validada para evitar que un cambio de DNS (rebinding) redirija a la red interna.
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$info['host']}:{$info['port']}:{$info['ips'][0]}"]];
        }

        try {
            return Http::withUserAgent((string) config('research.user_agent'))
                ->withOptions($options)
                ->timeout((int) config('research.timeout_seconds'))
                ->withHeaders(['Accept' => 'text/html,text/plain;q=0.9'])
                ->get($url);
        } catch (\Throwable $e) {
            throw new WebGateException('Error de red al descargar la página: ' . $e->getMessage());
        }
    }

    private function readLimited(\Illuminate\Http\Client\Response $response): string
    {
        $max = (int) config('research.max_bytes');
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (!$stream->eof() && strlen($body) < $max) {
            $chunk = $stream->read(min(8192, $max - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return $body;
    }

    // ---- robots.txt ----------------------------------------------------------------------------

    /**
     * @param array{scheme: string, host: string, port: int, ips: list<string>, path: string} $info
     */
    private function robotsAllows(array $info): bool
    {
        $origin = "{$info['scheme']}://{$info['host']}:{$info['port']}";

        if (!array_key_exists($origin, $this->robotsCache)) {
            $this->robotsCache[$origin] = $this->loadRobots($origin, $info);
        }

        $rules = $this->robotsCache[$origin];
        if ($rules === null) {
            return false; // robots.txt inaccesible (401/403/5xx/red): criterio conservador
        }

        $path = $info['path'];
        $bestLength = -1;
        $allowed = true;

        foreach ($rules as $rule) {
            [$type, $pattern] = $rule;
            if ($pattern === '') {
                continue;
            }
            if ($this->robotsPatternMatches($pattern, $path) && strlen($pattern) >= $bestLength) {
                // En empate gana Allow
                if (strlen($pattern) > $bestLength || $type === 'allow') {
                    $allowed = $type === 'allow';
                }
                $bestLength = strlen($pattern);
            }
        }

        return $allowed;
    }

    /**
     * @param array{scheme: string, host: string, port: int, ips: list<string>, path: string} $info
     * @return list<array{0: string, 1: string}>|null
     */
    private function loadRobots(string $origin, array $info): ?array
    {
        try {
            $response = $this->request("{$origin}/robots.txt", $info);
        } catch (WebGateException) {
            return null;
        }

        $status = $response->status();
        if (in_array($status, [401, 403], true) || $status >= 500) {
            return null;
        }
        if ($status >= 400 || $status >= 300) {
            return []; // sin robots.txt utilizable: sin restricciones
        }

        $text = $this->readLimited($response);
        $specific = [];
        $generic = [];
        $groupAgents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $groupAgents = [];
                    $inRules = false;
                }
                $groupAgents[] = strtolower($value);
            } elseif (in_array($field, ['allow', 'disallow'], true)) {
                $inRules = true;
                foreach ($groupAgents as $agent) {
                    if ($agent === '*') {
                        $generic[] = [$field, $value];
                    } elseif (str_contains(strtolower((string) config('research.user_agent')), $agent)) {
                        $specific[] = [$field, $value];
                    }
                }
            }
        }

        return $specific ?: $generic;
    }

    private function robotsPatternMatches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = rtrim($pattern, '$');
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';

        return preg_match($regex, $path) === 1;
    }

    // ---- utilidades ----------------------------------------------------------------------------

    private function isPublicIp(string $ip): bool
    {
        // IPv4 embebida en IPv6 (::ffff:127.0.0.1)
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // CGNAT 100.64.0.0/10 no lo cubre PHP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
                return false;
            }
        }

        return true;
    }

    private function absolutize(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }

        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = rtrim(dirname($b['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }
}
