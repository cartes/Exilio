<?php

namespace Tests\Feature;

use App\Services\Research\ContentExtractor;
use App\Services\Research\WebGate;
use App\Services\Research\WebGateException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebGateTest extends TestCase
{
    private function gate(string $ip = '93.184.216.34'): WebGate
    {
        return new WebGate(fn (string $host): array => [$ip]);
    }

    /** @dataProvider */
    public static function forbiddenUrls(): array
    {
        return [
            'esquema file' => ['file:///etc/passwd'],
            'esquema ftp' => ['ftp://example.com/x'],
            'credenciales' => ['https://user:pass@example.com/'],
            'puerto no permitido' => ['http://example.com:8080/'],
            'localhost' => ['http://localhost/'],
            'subdominio bloqueado' => ['https://router.local/'],
            'host sin punto' => ['http://intranet/'],
            'loopback' => ['http://127.0.0.1/'],
            'privada 10' => ['http://10.0.0.5/'],
            'privada 192' => ['http://192.168.1.1/'],
            'metadatos cloud' => ['http://169.254.169.254/latest/meta-data'],
            'cgnat' => ['http://100.64.0.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv4 mapeada' => ['http://[::ffff:127.0.0.1]/'],
            'url invalida' => ['not a url'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenUrls')]
    public function test_rejects_forbidden_urls(string $url): void
    {
        $this->expectException(WebGateException::class);
        $this->gate()->assertAllowed($url);
    }

    public function test_rejects_public_hostname_that_resolves_to_private_ip(): void
    {
        $this->expectException(WebGateException::class);
        $this->expectExceptionMessage('no pública');
        $this->gate('10.1.2.3')->assertAllowed('https://parece-publico.example.com/');
    }

    public function test_allows_normal_public_url(): void
    {
        $info = $this->gate()->assertAllowed('https://example.com/ruta?q=1');
        $this->assertSame('example.com', $info['host']);
        $this->assertSame(443, $info['port']);
        $this->assertSame('/ruta?q=1', $info['path']);
    }

    public function test_redirect_to_internal_address_is_blocked(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/a' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
            '*' => Http::response('SECRETO', 200, ['Content-Type' => 'text/plain']),
        ]);

        try {
            $this->gate()->fetch('https://example.com/a');
            $this->fail('Debió bloquear la redirección interna.');
        } catch (WebGateException $e) {
            $this->assertStringContainsString('no pública', $e->getMessage());
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
    }

    public function test_follows_safe_relative_redirect(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/viejo' => Http::response('', 301, ['Location' => '/nuevo']),
            'https://example.com/nuevo' => Http::response('hola', 200, ['Content-Type' => 'text/plain']),
        ]);

        $page = $this->gate()->fetch('https://example.com/viejo');
        $this->assertSame('https://example.com/nuevo', $page['url']);
        $this->assertSame('hola', $page['body']);
    }

    public function test_robots_txt_disallow_is_respected(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /privado\nAllow: /privado/abierto", 200),
            'https://example.com/*' => fn () => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);
        $gate = $this->gate();

        $this->assertSame('ok', $gate->fetch('https://example.com/publico')['body']);
        $this->assertSame('ok', $gate->fetch('https://example.com/privado/abierto')['body']);

        $this->expectException(WebGateException::class);
        $this->expectExceptionMessage('robots.txt');
        $gate->fetch('https://example.com/privado/secreto');
    }

    public function test_unreachable_robots_txt_is_treated_conservatively(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('error', 503),
            'https://example.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->expectException(WebGateException::class);
        $this->gate()->fetch('https://example.com/x');
    }

    public function test_disallowed_content_type_is_rejected(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/doc.pdf' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->expectException(WebGateException::class);
        $this->expectExceptionMessage('Tipo de contenido');
        $this->gate()->fetch('https://example.com/doc.pdf');
    }

    public function test_body_is_truncated_to_max_bytes(): void
    {
        config(['research.max_bytes' => 100]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/grande' => Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->assertSame(100, strlen($this->gate()->fetch('https://example.com/grande')['body']));
    }

    public function test_disabled_switch_blocks_every_request(): void
    {
        config(['research.enabled' => false]);
        Http::fake();

        try {
            $this->gate()->fetch('https://example.com/');
            $this->fail('Debió bloquear.');
        } catch (WebGateException) {
            Http::assertNothingSent();
            $this->assertTrue(true);
        }
    }

    public function test_extractor_drops_scripts_and_navigation_and_ranks_sentences(): void
    {
        $html = '<html><head><title> Redes  modulares </title><script>alert("x")</script></head><body>'
            . '<nav>Inicio Contacto Menú principal del sitio</nav>'
            . '<article><p>Las redes modulares presentan una mayor resiliencia frente a fallos aleatorios de sus nodos.</p>'
            . '<p>El clima de la región fue templado durante toda la semana pasada según los registros.</p></article>'
            . '<footer>Pie de página con avisos legales y cookies</footer></body></html>';

        $e = new ContentExtractor();
        $out = $e->extract($html, 'text/html');

        $this->assertSame('Redes modulares', $out['title']);
        $this->assertStringNotContainsString('alert', $out['text']);
        $this->assertStringNotContainsString('Menú principal', $out['text']);
        $this->assertStringNotContainsString('avisos legales', $out['text']);

        $top = $e->relevantSentences($out['text'], 'resiliencia de redes modulares', 1);
        $this->assertCount(1, $top);
        $this->assertStringContainsString('resiliencia', $top[0]['text']);
    }

    public function test_extractor_does_not_glue_headings_to_sentences(): void
    {
        $html = '<html><body><div><h2>Reflexiones finales</h2>'
            . '<div>En las redes libres de escala fluye a mayor velocidad la información debido al camino promedio corto.</div>'
            . '<ul><li>Primer elemento de lista sin relación con la consulta realizada por el auditor local.</li></ul></div></body></html>';

        $e = new ContentExtractor();
        $out = $e->extract($html, 'text/html');
        $top = $e->relevantSentences($out['text'], 'redes libres de escala información', 2);

        $this->assertNotEmpty($top);
        $this->assertStringStartsWith('En las redes', $top[0]['text']);
        $this->assertStringNotContainsString('Reflexiones finales', $top[0]['text']);
    }
}
