<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ContaCloserRepository;
use App\Repositories\VendedorRepository;
use PDOException;
use RuntimeException;

/**
 * A gestão dos acessos ao portal do closer pelo ADMIN (aba Equipes): listar as contas, aprovar (vinculando ou criando o closer), corrigir um pedido,
 * rejeitar, revogar, reconsiderar, excluir, gerar o convite de cadastro e o token de redefinição de senha. Porte fiel das ações get_acessos e acesso_*
 * do api.php (mesmas regras, mesma ordem de validação, mesmas mensagens). "SEM ACESSO" não é um status guardado: é o closer sem conta.
 * Nenhuma resposta traz o hash da senha nem o do token; o texto do token e do convite só aparece UMA vez, ao gerar.
 *
 * Cada método devolve o corpo de sucesso (sem o "success") ou ['erro' => mensagem, 'campo' => campo ou null].
 */
final class AcessoCloserService
{
    private const DIAS_DO_CONVITE = 7;
    private const HORAS_DO_TOKEN = 24;

    public function __construct(
        private ContaCloserRepository $contas,
        private VendedorRepository $vendedores,
        private MetasService $metas,
        private ConfiguracaoRepository $config
    ) {
    }

    private function agora(): string
    {
        return Relogio::agora()->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function erro(string $mensagem, ?string $campo = null): array
    {
        return ['erro' => $mensagem, 'campo' => $campo];
    }

    /** @return array<string, mixed> */
    public function listar(): array
    {
        $agora = $this->agora();
        $dados = array_map(static function (array $r) use ($agora): array {
            $equipe = $r['vendedor_id'] ? $r['v_equipe'] : $r['equipe_pedida'];
            return [
                'id' => (int) $r['id'], 'vendedor_id' => $r['vendedor_id'] ? (int) $r['vendedor_id'] : null,
                'equipe_id' => $equipe ? (int) $equipe : null, 'nome' => $r['nome'], 'email' => $r['email'], 'status' => $r['status'],
                'status_motivo' => $r['status_motivo'], 'status_em' => $r['status_em'], 'criado_em' => $r['created_at'],
                'ultimo_login' => $r['ultimo_login'], 'vendedor_nome' => $r['v_nome'], 'vendedor_arquivado' => $r['v_deleted'] !== null,
                'reset_pedido_em' => $r['reset_solicitado_em'],
                'token_ativo_ate' => ($r['tem_token'] && $r['reset_expira_em'] && $r['reset_expira_em'] > $agora) ? $r['reset_expira_em'] : null,
            ];
        }, $this->contas->todasParaAdmin());
        return ['data' => $dados, 'convites' => (object) $this->contas->convitesAbertos($agora), 'now' => $agora];
    }

    /**
     * Libera o acesso. Pedido sem closer vinculado: o admin escolhe um closer existente ($vendedorId) OU manda criar um novo na equipe pedida ($criarNovo).
     * @return array<string, mixed>
     */
    public function aprovar(int $id, int $vendedorId, bool $criarNovo): array
    {
        $conta = $this->contas->contaPorId($id);
        if ($conta === null) {
            return $this->erro('Pedido de acesso não encontrado');
        }
        if ($conta['status'] === 'ativo') {
            return $this->erro('Este acesso já está ativo');
        }
        $vinculado = $conta['vendedor_id'] ? (int) $conta['vendedor_id'] : 0;
        try {
            // o padrão de metas do closer novo é calculado ANTES de criá-lo: ele não pode contar com ele mesmo
            $heranca = (!$vinculado && !$vendedorId && $criarNovo) ? $this->metas->heranca() : null;
            $this->contas->emTransacao(function () use ($id, $conta, $vinculado, $vendedorId, $criarNovo, $heranca): void {
                $vid = $vinculado;
                if (!$vid) {
                    if ($vendedorId) {
                        if (!$this->vendedores->ativoExiste($vendedorId)) {
                            throw new RuntimeException('Closer escolhido não encontrado');
                        }
                        $vid = $vendedorId;
                    } elseif ($criarNovo) {
                        if ($this->vendedores->nomeEmUso((string) $conta['nome'])) {
                            throw new RuntimeException('Já existe um closer com esse nome — vincule a conta a ele');
                        }
                        $vid = $this->vendedores->criar($conta['equipe_id'] ? (int) $conta['equipe_id'] : null, (string) $conta['nome'], $heranca['metas'], $heranca['mensal'], '#d4af37', null, random_int(1, 70));
                        $this->metas->aplicarHeranca($vid, $heranca);
                        $this->vendedores->criarDiasDaSemana($vid, $this->config->diasDaSemana());
                    } else {
                        throw new RuntimeException('Escolha o closer ao qual esta conta pertence (ou crie um novo)');
                    }
                    $this->contas->vincularAoCloser($id, $vid);
                } else {
                    $situacao = $this->vendedores->situacao($vid);
                    if ($situacao === null || $situacao['deleted_at'] !== null) {
                        throw new RuntimeException('O closer vinculado está arquivado. Reative-o antes de liberar o acesso');
                    }
                }
                $this->contas->ativar($id, $this->agora());
            });
        } catch (PDOException $e) {   // antes de RuntimeException: PDOException herda dela
            if ($e->getCode() === '23000') {
                return $this->erro('Esse closer já tem outra conta de acesso');
            }
            throw $e;
        } catch (RuntimeException $e) {
            return $this->erro($e->getMessage());
        }
        return ['message' => 'Acesso liberado — o closer já pode entrar no portal'];
    }

    /**
     * Corrige um pedido ainda PENDENTE (nome, e-mail e equipe) antes de aprovar. Pedido já vinculado a um closer: a equipe é a do closer, então só nome e e-mail mudam.
     * @param mixed $nome @param mixed $email
     * @return array<string, mixed>
     */
    public function editar(int $id, $nome, $email, int $equipeId): array
    {
        $nome = Texto::limpar($nome, 100);
        $email = AutenticacaoCloserService::normalizarEmail($email);
        $conta = $this->contas->contaPorId($id);
        if ($conta === null) {
            return $this->erro('Pedido de acesso não encontrado');
        }
        if ($conta['status'] !== 'pendente') {
            return $this->erro('Só dá para editar pedidos pendentes.');
        }
        if (Texto::tamanho($nome) < 3) {
            return $this->erro('Informe o nome completo.', 'nome');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            return $this->erro('Informe um e-mail válido.', 'email');
        }
        if ($this->contas->emailEmUsoPorOutra($email, $id)) {
            return $this->erro('Este e-mail já tem cadastro.', 'email');
        }
        $semCloser = !$conta['vendedor_id'];
        if ($semCloser && $equipeId && !$this->contas->equipeExiste($equipeId)) {
            return $this->erro('Equipe não encontrada.', 'equipe_id');
        }
        $this->contas->corrigirPedido($id, $nome, $email, $semCloser, $equipeId ?: null);
        return ['message' => 'Pedido atualizado'];
    }

    /** @param mixed $motivo @return array<string, mixed> */
    public function rejeitar(int $id, $motivo): array
    {
        $motivo = Texto::limpar($motivo, 255);
        if (!$this->contas->rejeitarPendente($id, $motivo !== '' ? $motivo : null, $this->agora())) {
            return $this->erro('Só é possível rejeitar pedidos pendentes');
        }
        return ['message' => 'Acesso rejeitado'];
    }

    /** Derruba o login na hora (o portal revalida o status a cada requisição) e mantém todo o histórico. @return array<string, mixed> */
    public function revogar(int $id): array
    {
        if (!$this->contas->revogarAtiva($id, $this->agora())) {
            return $this->erro('Só é possível revogar acessos ativos');
        }
        return ['message' => 'Acesso revogado (histórico mantido)'];
    }

    /** @return array<string, mixed> */
    public function reconsiderar(int $id): array
    {
        if (!$this->contas->reabrirRejeitada($id, $this->agora())) {
            return $this->erro('Só é possível reconsiderar acessos rejeitados');
        }
        return ['message' => 'Pedido reaberto — aprove ou rejeite novamente'];
    }

    /** Exclui só o pedido/conta (nunca o closer nem o histórico de ações). Conta ativa: revogar primeiro. @return array<string, mixed> */
    public function excluir(int $id): array
    {
        if (!$this->contas->excluirInativa($id)) {
            return $this->erro('Revogue o acesso antes de excluir a conta');
        }
        return ['message' => 'Pedido excluído'];
    }

    /** Convite = link de cadastro de uso único (7 dias) já vinculado a um closer existente que ainda não tem conta. @return array<string, mixed> */
    public function convite(int $vendedorId): array
    {
        $closer = $this->vendedores->nomes([$vendedorId]);
        if (!$this->vendedores->ativoExiste($vendedorId) || !isset($closer[$vendedorId])) {
            return $this->erro('Closer não encontrado');
        }
        if ($this->contas->closerTemConta($vendedorId)) {
            return $this->erro('Este closer já tem uma conta de acesso');
        }
        $token = bin2hex(random_bytes(20));
        $ate = Relogio::agora()->modify('+' . self::DIAS_DO_CONVITE . ' days')->format('Y-m-d H:i:s');
        $this->contas->trocarConvite($vendedorId, hash('sha256', $token), $ate);
        return ['nome' => $closer[$vendedorId], 'path' => '/closer/cadastro?convite=' . $token, 'expira_em' => $ate];
    }

    /** Token de redefinição de senha: só o admin vê o texto (uma vez); no banco fica o sha256 e a validade de 24 h. @return array<string, mixed> */
    public function tokenDeSenha(int $id): array
    {
        $conta = $this->contas->contaAtiva($id);
        if ($conta === null) {
            return $this->erro('Só contas ativas podem redefinir a senha');
        }
        // 10 caracteres sem ambíguos (sem 0/O/1/I/L), exibido como XXXXX-XXXXX
        $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < 10; $i++) {
            $token .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $ate = Relogio::agora()->modify('+' . self::HORAS_DO_TOKEN . ' hours')->format('Y-m-d H:i:s');
        $this->contas->gravarTokenDeSenha($id, hash('sha256', $token), $ate);
        return ['nome' => $conta['nome'], 'email' => $conta['email'], 'token' => substr($token, 0, 5) . '-' . substr($token, 5), 'expira_em' => $ate];
    }
}
