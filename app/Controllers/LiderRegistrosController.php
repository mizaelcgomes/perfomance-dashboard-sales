<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Relogio;
use App\Core\Request;
use App\Core\Resultado;
use App\Core\Response;
use App\Repositories\EquipeRepository;
use App\Repositories\VendedorRepository;
use App\Services\AgendaLiderService;
use App\Services\CobrancaCadastroService;
use App\Services\FeedbackService;
use App\Services\ReuniaoService;
use App\Services\RevisaoAtendimentoService;
use App\Services\TabelasDoLider;
use App\Services\TreinamentoService;
use Throwable;

/**
 * Registros da liderança (fase L3): reuniões, feedbacks, cobranças de cadastramento, revisões de atendimento e treinamentos.
 * Um controller só, com as quatro operações (listar, criar/editar, ação) para as cinco entidades. A equipe e o líder vêm SEMPRE do
 * middleware auth:lider (parâmetro equipe_id) e do cadastro da equipe: nada na requisição escolhe a equipe, então o líder só enxerga e só
 * grava na equipe dele. Não há exclusão.
 */
final class LiderRegistrosController
{
    private const ENTIDADES = ['reunioes', 'feedbacks', 'cobrancas', 'revisoes', 'treinamentos'];

    public function __construct(
        private ReuniaoService $reunioes,
        private FeedbackService $feedbacks,
        private CobrancaCadastroService $cobrancas,
        private RevisaoAtendimentoService $revisoes,
        private TreinamentoService $treinamentos,
        private EquipeRepository $equipes,
        private VendedorRepository $vendedores,
        private AgendaLiderService $agenda
    ) {
    }

    /** Lista os registros da entidade, mais os closers da equipe (para os menus) e a data de hoje. @param array<string, string> $p */
    public function listar(Request $r, array $p = []): Response
    {
        return $this->protegido(fn(): Response => $this->listarDados($r, $p));
    }

    /** @param array<string, string> $p */
    private function listarDados(Request $r, array $p): Response
    {
        [$equipe, $lider, $entidade] = $this->contexto($r, $p);
        if ($entidade === null) {
            return $this->naoEncontrada();
        }
        $itens = match ($entidade) {
            'reunioes' => $this->reunioes->listar($equipe),
            'feedbacks' => $this->feedbacks->listar($equipe),
            'cobrancas' => $this->cobrancas->listar($equipe),
            'revisoes' => $this->revisoes->listar($equipe),
            default => $this->treinamentos->listar($equipe),
        };
        $time = $this->vendedores->doTime($equipe);
        // closers: quem pode receber um registro (a equipe sem o líder); time: todos, com o líder marcado (lista de presença em treinamento)
        $closers = array_values(array_filter($time, static fn(array $v): bool => $v['id'] !== $lider));
        $membros = array_map(static fn(array $v): array => $v + ['lider' => $v['id'] === $lider], $time);
        return Response::json(['success' => true, 'entidade' => $entidade, 'itens' => $itens, 'closers' => $closers, 'time' => $membros, 'hoje' => Relogio::hoje()]);
    }

    /** Cria (sem id) ou edita (com id, na rota ou no corpo) um registro. @param array<string, string> $p */
    public function salvar(Request $r, array $p = []): Response
    {
        $id = (int) ($p['id'] ?? $r->entrada('id', 0));
        return $id > 0 ? $this->editar($r, $p + ['id' => (string) $id]) : $this->criar($r, $p);
    }

    /** @param array<string, string> $p */
    public function criar(Request $r, array $p = []): Response
    {
        return $this->protegido(fn(): Response => $this->criarDados($r, $p));
    }

    /** @param array<string, string> $p */
    private function criarDados(Request $r, array $p): Response
    {
        [$equipe, $lider, $entidade] = $this->contexto($r, $p);
        if ($entidade === null) {
            return $this->naoEncontrada();
        }
        $e = fn(string $campo) => $r->entrada($campo);
        $resultado = match ($entidade) {
            'reunioes' => $this->reunioes->criar($equipe, $lider, $e('titulo'), $e('data_hora_marcada'), $e('participantes')),
            'feedbacks' => $this->feedbacks->criar($equipe, $lider, $e('closer_id'), $e('assunto'), $e('texto'), $e('data')),
            'cobrancas' => $this->cobrancas->criar($equipe, $lider, $e('closer_id'), $e('observacao'), $e('data')),
            'revisoes' => $this->revisoes->criar($equipe, $lider, $e('closer_id'), $e('telefone'), $e('observacao'), $e('data')),
            default => $this->treinamentos->criar($equipe, $lider, $e('data_hora'), $e('assunto')),
        };
        return $this->resposta($resultado);
    }

    /** @param array<string, string> $p */
    public function editar(Request $r, array $p = []): Response
    {
        return $this->protegido(fn(): Response => $this->editarDados($r, $p));
    }

    /** @param array<string, string> $p */
    private function editarDados(Request $r, array $p): Response
    {
        [$equipe, , $entidade] = $this->contexto($r, $p);
        $id = (int) ($p['id'] ?? $r->entrada('id', 0));
        if ($entidade === null || $id <= 0) {
            return $this->naoEncontrada();
        }
        $e = fn(string $campo) => $r->entrada($campo);
        $resultado = match ($entidade) {
            'reunioes' => $this->reunioes->editar($equipe, $id, $e('titulo'), $e('data_hora_marcada'), $e('data_hora_realizada'), $e('participantes')),
            'feedbacks' => $this->feedbacks->editar($equipe, $id, $e('assunto'), $e('texto')),
            'cobrancas' => $this->cobrancas->editar($equipe, $id, $e('observacao')),
            'revisoes' => $this->revisoes->editar($equipe, $id, $e('telefone'), $e('observacao')),
            default => $this->treinamentos->editar($equipe, $id, $e('data_hora'), $e('assunto')),
        };
        return $this->resposta($resultado);
    }

    /** Ações de estado: reunião realizar/cancelar e presença do treinamento. Parâmetros: entidade, id e acao. @param array<string, string> $p */
    public function acao(Request $r, array $p = []): Response
    {
        return $this->protegido(fn(): Response => $this->acaoDados($r, $p));
    }

    /** @param array<string, string> $p */
    private function acaoDados(Request $r, array $p): Response
    {
        [$equipe, $lider, $entidade] = $this->contexto($r, $p);
        $id = (int) ($p['id'] ?? $r->entrada('id', 0));
        $acao = (string) ($p['acao'] ?? $r->entrada('acao', ''));
        if ($entidade === null || $id <= 0) {
            return $this->naoEncontrada();
        }
        if ($entidade === 'reunioes' && $acao === 'realizar') {
            return $this->resposta($this->reunioes->realizar($equipe, $id, $r->entrada('data_hora_realizada')));
        }
        if ($entidade === 'reunioes' && $acao === 'cancelar') {
            return $this->resposta($this->reunioes->cancelar($equipe, $id));
        }
        if ($entidade === 'treinamentos' && $acao === 'presencas') {
            return $this->resposta($this->treinamentos->definirPresencas($equipe, $id, $r->entrada('presentes')));
        }
        if ($entidade === 'treinamentos' && $acao === 'cancelar') {
            return $this->resposta($this->treinamentos->cancelar($equipe, $id));
        }
        // nota no evento da agenda (só acréscimo)
        if (($entidade === 'reunioes' || $entidade === 'treinamentos') && $acao === 'nota') {
            return $this->resposta($this->agenda->nota($equipe, $lider, $entidade === 'reunioes' ? 'reuniao' : 'treinamento', $id, $r->entrada('texto')));
        }
        return $this->naoEncontrada();
    }

    /**
     * Equipe e líder da requisição + a entidade pedida (ou null se não for uma das cinco).
     * @param array<string, string> $p
     * @return array{0: int, 1: int, 2: ?string}
     */
    private function contexto(Request $r, array $p): array
    {
        $equipe = (int) ($p['equipe_id'] ?? 0);
        $linha = $this->equipes->comLider($equipe);
        $lider = $linha === null || $linha['lider_id'] === null ? 0 : (int) $linha['lider_id'];
        $entidade = (string) ($p['entidade'] ?? $r->entrada('entidade', ''));
        return [$equipe, $lider, in_array($entidade, self::ENTIDADES, true) ? $entidade : null];
    }

    /** Tabelas do módulo ainda não criadas: 503 com a instrução de rodar a migração; qualquer outro erro segue para o tratamento padrão. */
    private function protegido(callable $acao): Response
    {
        try {
            return $acao();
        } catch (Throwable $erro) {
            if (!TabelasDoLider::ausente($erro)) {
                throw $erro;
            }
            return Response::json(['success' => false, 'code' => 'migracao', 'message' => TabelasDoLider::MENSAGEM], 503);
        }
    }

    private function resposta(Resultado $resultado): Response
    {
        $naoAchou = !$resultado->sucesso() && strpos($resultado->mensagem(), 'não encontrad') !== false;
        return $resultado->paraResponse($naoAchou ? 404 : 422);
    }

    private function naoEncontrada(): Response
    {
        return Response::json(['success' => false, 'message' => 'Registro não encontrado.'], 404);
    }
}
