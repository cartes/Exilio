<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\ChatMessages;
use App\Services\IntrospectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChatQualityTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::where('name', 'EXILIO-001')->firstOrFail();
    }

    private function useClaude(): void
    {
        foreach (['AI_PROVIDER' => 'claude', 'ANTHROPIC_API_KEY' => 'sk-ant-test'] as $k => $v) {
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }

    private function claudeReply(array $body): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response($body + [
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 12],
        ])]);
    }

    public function test_model_refusal_is_not_presented_as_the_local_state_dump(): void
    {
        $this->useClaude();
        $this->claudeReply(['content' => [], 'stop_reason' => 'refusal']);

        $res = $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => '¿Podemos engañar a una IA para ganar dinero?'])
            ->assertStatus(201);

        $text = $res->json('agent_message.content');
        $this->assertStringContainsString('prefirió no responder', $text);
        $this->assertStringNotContainsString('Respuesta introspectiva determinista', $text);
        $this->assertStringNotContainsString('versión v', $text);
        $this->assertTrue($res->json('agent_message.grounding_snapshot.no_model_text'));
    }

    public function test_empty_model_text_is_reported_honestly(): void
    {
        $this->useClaude();
        $this->claudeReply(['content' => [['type' => 'thinking', 'thinking' => '...']], 'stop_reason' => 'end_turn']);

        $text = $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Hola'])
            ->assertStatus(201)->json('agent_message.content');

        $this->assertStringContainsString('No recibí una respuesta', $text);
    }

    public function test_real_reply_is_returned_untouched_and_history_reaches_the_model(): void
    {
        $this->useClaude();
        $this->claudeReply(['content' => [['type' => 'text', 'text' => 'Mi postura: no hace falta engañar a nadie.']], 'stop_reason' => 'end_turn']);

        $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Primera pregunta'])->assertStatus(201);
        $res = $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Segunda pregunta'])->assertStatus(201);

        $this->assertSame('Mi postura: no hace falta engañar a nadie.', $res->json('agent_message.content'));

        // En la segunda llamada el modelo recibe el turno previo (user, assistant) y luego el mensaje nuevo.
        Http::assertSent(function ($request) {
            $m = $request['messages'] ?? [];
            return count($m) === 3
                && $m[0] === ['role' => 'user', 'content' => 'Primera pregunta']
                && $m[1]['role'] === 'assistant'
                && $m[2] === ['role' => 'user', 'content' => 'Segunda pregunta'];
        });
    }

    public function test_turns_without_model_text_are_not_fed_back_as_history(): void
    {
        $this->useClaude();
        $this->claudeReply(['content' => [], 'stop_reason' => 'refusal']);
        $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Pregunta rechazada'])->assertStatus(201);

        $this->claudeReply(['content' => [['type' => 'text', 'text' => 'Respuesta normal']], 'stop_reason' => 'end_turn']);
        $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Otra pregunta'])->assertStatus(201);

        Http::assertSent(function ($request) {
            $joined = json_encode($request['messages'] ?? []);
            return str_contains($joined, 'Otra pregunta')
                && !str_contains($joined, 'prefirió no responder')
                && !str_contains($joined, 'No recibí');
        });
    }

    public function test_gemini_empty_candidates_flag_empty_response(): void
    {
        foreach (['AI_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k'] as $key => $v) {
            $_ENV[$key] = $v;
            $_SERVER[$key] = $v;
        }
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 0, 'totalTokenCount' => 10],
        ])]);

        $text = $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Hola'])
            ->assertStatus(201)->json('agent_message.content');

        $this->assertStringContainsString('No recibí una respuesta', $text);
    }

    public function test_chat_messages_builder_alternates_roles_and_starts_with_user(): void
    {
        $turns = ChatMessages::build([
            ['role' => 'assistant', 'content' => 'huérfano al inicio'],
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
            ['role' => 'agent', 'content' => 'c'],
            ['role' => 'user', 'content' => '   '],
        ], 'actual');

        $this->assertSame([
            ['role' => 'user', 'content' => "a\n\nb"],
            ['role' => 'assistant', 'content' => 'c'],
            ['role' => 'user', 'content' => 'actual'],
        ], $turns);
    }

    public function test_prompt_is_clean_top_interests_only_and_no_chat_echo(): void
    {
        $interests = [];
        foreach (range(1, 8) as $i) {
            $interests["Tema {$i}"] = ['topic' => "Tema {$i}", 'curiosity_score' => $i / 10, 'evidence_memory_ids' => []];
        }
        $this->agent->update(['attributes' => ['personality' => [], 'interests' => $interests]]);

        $this->agent->memories()->create([
            'type' => 'episodic', 'epistemic_status' => 'fact', 'origin' => 'user_input',
            'content' => 'El interlocutor dijo en el diálogo: "ECO_DEL_CHAT"', 'evidence_ref' => 'chat-console',
        ]);
        $this->agent->memories()->create([
            'type' => 'episodic', 'epistemic_status' => 'fact', 'origin' => 'manual_entry', 'content' => 'DATO_REAL_VERIFICADO',
        ]);

        $sys = app(IntrospectionService::class)->buildContext($this->agent->fresh(), 'hola')['system_instruction'];

        $this->assertStringContainsString('Tema 8', $sys);
        $this->assertStringContainsString('Tema 4', $sys);
        $this->assertStringNotContainsString('Tema 3', $sys);   // solo los 5 de mayor curiosidad
        $this->assertStringNotContainsString('ECO_DEL_CHAT', $sys);
        $this->assertStringContainsString('DATO_REAL_VERIFICADO', $sys);
        $this->assertStringContainsString('engañar', $sys);      // pauta para peticiones dudosas
        $this->assertStringNotContainsStringIgnoringCase('cripto', $sys);
    }

    public function test_truncated_reply_is_trimmed_to_the_last_full_sentence(): void
    {
        $this->assertSame('Primera idea. Segunda idea.', ChatMessages::trimToLastSentence('Primera idea. Segunda idea. Y si el mod'));
        $this->assertSame('Texto completo.', ChatMessages::trimToLastSentence('Texto completo.'));
        $this->assertSame('{"a": "cortado', ChatMessages::trimToLastSentence('{"a": "cortado'));
        $this->assertSame('sin punto final…', ChatMessages::trimToLastSentence('sin punto final'));
    }

    public function test_max_tokens_reply_from_claude_is_not_left_mid_sentence(): void
    {
        $this->useClaude();
        $this->claudeReply(['content' => [['type' => 'text', 'text' => 'Una postura clara. Un matiz importante. Y luego viene la par']], 'stop_reason' => 'max_tokens']);

        $text = $this->postJson("/api/agents/{$this->agent->id}/chat", ['message' => 'Hola'])->assertStatus(201)->json('agent_message.content');

        $this->assertSame('Una postura clara. Un matiz importante.', $text);
    }
}
