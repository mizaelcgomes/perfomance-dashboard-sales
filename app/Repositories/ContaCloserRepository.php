<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Contas de login do portal do closer (tabela closer_usuarios). Só as leituras de que a produtividade e o módulo do líder precisam. */
final class ContaCloserRepository
{
    public function __construct(private Database $banco)
    {
    }

    /** Vendedores que têm conta ATIVA no portal: só eles entram nos alertas de inatividade. @return array<int, true> */
    public function vendedoresComContaAtiva(): array
    {
        $saida = [];
        foreach ($this->banco->consultar("SELECT vendedor_id FROM closer_usuarios WHERE status='ativo' AND vendedor_id IS NOT NULL") as $r) {
            $saida[(int) $r['vendedor_id']] = true;
        }
        return $saida;
    }

    /** A conta é de um closer de equipe oculta da TV: essa equipe não tem portal (nem do closer, nem do líder). */
    public function pertenceAEquipeOculta(int $contaId): bool
    {
        return (bool) $this->banco->valor(
            'SELECT 1 FROM closer_usuarios u JOIN vendedores v ON v.id = u.vendedor_id JOIN equipes e ON e.id = v.equipe_id WHERE u.id = ? AND e.oculta_tv = 1',
            [$contaId]
        );
    }

    /** O vendedor (closer) de uma conta ATIVA do portal, ou null (conta pendente, rejeitada ou sem vínculo). O id da conta vem da sessão. */
    public function vendedorDaConta(int $contaId): ?int
    {
        $v = $this->banco->valor("SELECT vendedor_id FROM closer_usuarios WHERE id = ? AND status = 'ativo' AND vendedor_id IS NOT NULL", [$contaId]);
        return $v === null || $v === false ? null : (int) $v;
    }

    // ── Cadastro, login e recuperação de senha (fase 6) ─────────────────────────────────────────────────────

    /** A conta com o closer e a equipe dela (nome, cor, se a equipe está oculta da TV), ou null. @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        return $this->banco->linha(
            'SELECT u.*, v.nome AS v_nome, v.equipe_id AS v_equipe, v.deleted_at AS v_deleted, v.cor AS v_cor,
                    e.nome AS equipe_nome, e.cor AS equipe_cor, e.oculta_tv AS equipe_oculta
               FROM closer_usuarios u
               LEFT JOIN vendedores v ON v.id = u.vendedor_id
               LEFT JOIN equipes e ON e.id = COALESCE(v.equipe_id, u.equipe_id)
              WHERE u.id = ?',
            [$id]
        );
    }

    public function idPorEmail(string $email): ?int
    {
        $id = $this->banco->valor('SELECT id FROM closer_usuarios WHERE email=?', [$email]);
        return $id === null || $id === false ? null : (int) $id;
    }

    /** Equipes para o cadastro (só id, nome e cor: lista pública). @return array<int, array<string, mixed>> */
    public function equipesPublicas(): array
    {
        return $this->banco->consultar('SELECT id, nome, cor FROM equipes ORDER BY nome');
    }

    public function equipeExiste(int $id): bool
    {
        return $this->banco->linha('SELECT id FROM equipes WHERE id=?', [$id]) !== null;
    }

    /** Closers da equipe que ainda não têm conta (o cadastro tenta vincular pelo nome). @return array<int, array<string, mixed>> id, nome */
    public function closersSemContaDaEquipe(int $equipeId): array
    {
        return $this->banco->consultar(
            'SELECT v.id, v.nome FROM vendedores v WHERE v.deleted_at IS NULL AND v.equipe_id = ?
                AND NOT EXISTS (SELECT 1 FROM closer_usuarios u WHERE u.vendedor_id = v.id)',
            [$equipeId]
        );
    }

    /** Convite válido (não usado, não expirado, closer ativo e sem conta): id, vendedor_id, nome, equipe_id; senão null. @return array<string, mixed>|null */
    public function conviteValido(string $tokenHash, string $agora): ?array
    {
        return $this->banco->linha(
            'SELECT c.id, c.vendedor_id, v.nome, v.equipe_id
               FROM closer_convites c JOIN vendedores v ON v.id = c.vendedor_id
              WHERE c.token_hash = ? AND c.usado_em IS NULL AND c.expira_em > ? AND v.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM closer_usuarios u WHERE u.vendedor_id = v.id)',
            [$tokenHash, $agora]
        );
    }

    /**
     * Cria a conta PENDENTE (e, se veio de convite, marca o convite como usado) numa transação.
     * @throws \PDOException com código 23000 se o e-mail já existe (quem chama trata a corrida)
     */
    public function criarPendente(?int $vendedorId, ?int $equipeId, string $nome, string $email, string $senhaHash, string $agora, ?string $conviteHash): void
    {
        $this->banco->transacao(function () use ($vendedorId, $equipeId, $nome, $email, $senhaHash, $agora, $conviteHash): void {
            $this->banco->executar(
                "INSERT INTO closer_usuarios (vendedor_id, equipe_id, nome, email, senha_hash, status, status_em) VALUES (?,?,?,?,?, 'pendente', ?)",
                [$vendedorId, $equipeId, $nome, $email, $senhaHash, $agora]
            );
            if ($conviteHash !== null) {
                $this->banco->executar('UPDATE closer_convites SET usado_em=? WHERE token_hash=?', [$agora, $conviteHash]);
            }
        });
    }

    public function registrarLogin(int $id, string $agora): void
    {
        $this->banco->executar('UPDATE closer_usuarios SET ultimo_login = ? WHERE id = ?', [$agora, $id]);
    }

    /** O closer pediu a redefinição: o admin vê o pedido no card dele. */
    public function marcarPedidoDeRedefinicao(string $email, string $agora): void
    {
        $this->banco->executar('UPDATE closer_usuarios SET reset_solicitado_em=? WHERE email=?', [$agora, $email]);
    }

    /** @return array<string, mixed>|null id, reset_token_hash, reset_expira_em */
    public function dadosDeRedefinicao(string $email): ?array
    {
        return $this->banco->linha('SELECT id, reset_token_hash, reset_expira_em FROM closer_usuarios WHERE email=?', [$email]);
    }

    /** Grava a senha nova, apaga o token e derruba os "lembrar de mim" antigos. */
    public function redefinirSenha(int $id, string $senhaHash): void
    {
        $this->banco->executar('UPDATE closer_usuarios SET senha_hash=?, reset_token_hash=NULL, reset_expira_em=NULL, reset_solicitado_em=NULL WHERE id=?', [$senhaHash, $id]);
        $this->esquecerTodosOsLembretes($id);
    }

    // ── "Lembrar de mim" (cookie de 7 dias: selector + validator; o validator só é guardado como hash) ──────

    public function guardarLembrete(int $contaId, string $selector, string $validatorHash, string $expiraEm, string $agora): void
    {
        $this->banco->executar('DELETE FROM closer_remember WHERE expira_em < ?', [$agora]);
        $this->banco->executar('INSERT INTO closer_remember (usuario_id, selector, validator_hash, expira_em) VALUES (?,?,?,?)', [$contaId, $selector, $validatorHash, $expiraEm]);
    }

    /** @return array<string, mixed>|null id, usuario_id, validator_hash (só se ainda não expirou) */
    public function lembretePorSelector(string $selector, string $agora): ?array
    {
        return $this->banco->linha('SELECT * FROM closer_remember WHERE selector = ? AND expira_em > ?', [$selector, $agora]);
    }

    public function apagarLembrete(int $id): void
    {
        $this->banco->executar('DELETE FROM closer_remember WHERE id = ?', [$id]);
    }

    public function apagarLembretePorSelector(string $selector): void
    {
        $this->banco->executar('DELETE FROM closer_remember WHERE selector = ?', [$selector]);
    }

    public function esquecerTodosOsLembretes(int $contaId): void
    {
        $this->banco->executar('DELETE FROM closer_remember WHERE usuario_id=?', [$contaId]);
    }

    /** O nome do líder da equipe do closer (página Configurações do portal), ou ''. */
    public function nomeDoLider(int $vendedorId): string
    {
        $nome = $this->banco->valor(
            'SELECT l.nome FROM vendedores v JOIN equipes e ON e.id = v.equipe_id JOIN vendedores l ON l.id = e.lider_id AND l.deleted_at IS NULL WHERE v.id = ?',
            [$vendedorId]
        );
        return $nome === null || $nome === false ? '' : (string) $nome;
    }

    // ── Gestão de acessos pelo admin (aprovar, rejeitar, convite, token de senha) ──────────────────────────

    /** Roda $acao numa transação (desfaz se lançar exceção). */
    public function emTransacao(callable $acao): void
    {
        $this->banco->transacao($acao);
    }

    /** Todas as contas, da mais nova para a mais antiga, com o closer vinculado (nome, equipe, arquivado). @return array<int, array<string, mixed>> */
    public function todasParaAdmin(): array
    {
        return $this->banco->consultar(
            'SELECT u.id, u.vendedor_id, u.equipe_id AS equipe_pedida, u.nome, u.email, u.status, u.status_motivo, u.status_em,
                    u.reset_solicitado_em, u.reset_expira_em, (u.reset_token_hash IS NOT NULL) AS tem_token, u.ultimo_login, u.created_at,
                    v.nome AS v_nome, v.equipe_id AS v_equipe, v.deleted_at AS v_deleted
               FROM closer_usuarios u LEFT JOIN vendedores v ON v.id = u.vendedor_id
              ORDER BY u.created_at DESC, u.id DESC'
        );
    }

    /** Convites ainda válidos: [vendedor_id] => validade. @return array<int, string> */
    public function convitesAbertos(string $agora): array
    {
        $saida = [];
        foreach ($this->banco->consultar('SELECT vendedor_id, MAX(expira_em) ate FROM closer_convites WHERE usado_em IS NULL AND expira_em > ? GROUP BY vendedor_id', [$agora]) as $c) {
            $saida[(int) $c['vendedor_id']] = (string) $c['ate'];
        }
        return $saida;
    }

    /** A conta inteira (sem o closer), ou null. @return array<string, mixed>|null */
    public function contaPorId(int $id): ?array
    {
        return $this->banco->linha('SELECT * FROM closer_usuarios WHERE id=?', [$id]);
    }

    public function vincularAoCloser(int $contaId, int $vendedorId): void
    {
        $this->banco->executar('UPDATE closer_usuarios SET vendedor_id=? WHERE id=?', [$vendedorId, $contaId]);
    }

    public function ativar(int $contaId, string $agora): void
    {
        $this->banco->executar("UPDATE closer_usuarios SET status='ativo', status_motivo=NULL, status_em=? WHERE id=?", [$agora, $contaId]);
    }

    public function emailEmUsoPorOutra(string $email, int $contaId): bool
    {
        return $this->banco->linha('SELECT id FROM closer_usuarios WHERE email=? AND id<>?', [$email, $contaId]) !== null;
    }

    /** Corrige nome e e-mail (e, sem closer vinculado, a equipe pedida). */
    public function corrigirPedido(int $contaId, string $nome, string $email, bool $comEquipe, ?int $equipeId): void
    {
        if ($comEquipe) {
            $this->banco->executar('UPDATE closer_usuarios SET nome=?, email=?, equipe_id=? WHERE id=?', [$nome, $email, $equipeId, $contaId]);
        } else {
            $this->banco->executar('UPDATE closer_usuarios SET nome=?, email=? WHERE id=?', [$nome, $email, $contaId]);
        }
    }

    /** Rejeita só se estiver pendente. @return bool se mudou */
    public function rejeitarPendente(int $contaId, ?string $motivo, string $agora): bool
    {
        return $this->banco->executar("UPDATE closer_usuarios SET status='rejeitado', status_motivo=?, status_em=? WHERE id=? AND status='pendente'", [$motivo, $agora, $contaId]) > 0;
    }

    /** Revoga só se estiver ativa e derruba os "lembrar de mim". @return bool se mudou */
    public function revogarAtiva(int $contaId, string $agora): bool
    {
        $mudou = $this->banco->executar("UPDATE closer_usuarios SET status='rejeitado', status_motivo='Acesso revogado pelo administrador', status_em=? WHERE id=? AND status='ativo'", [$agora, $contaId]) > 0;
        if ($mudou) {
            $this->esquecerTodosOsLembretes($contaId);
        }
        return $mudou;
    }

    /** Reabre só se estiver rejeitada. @return bool se mudou */
    public function reabrirRejeitada(int $contaId, string $agora): bool
    {
        return $this->banco->executar("UPDATE closer_usuarios SET status='pendente', status_motivo=NULL, status_em=? WHERE id=? AND status='rejeitado'", [$agora, $contaId]) > 0;
    }

    /** Exclui só a conta (nunca o closer) se não estiver ativa. @return bool se excluiu */
    public function excluirInativa(int $contaId): bool
    {
        $excluiu = $this->banco->executar("DELETE FROM closer_usuarios WHERE id=? AND status<>'ativo'", [$contaId]) > 0;
        if ($excluiu) {
            $this->esquecerTodosOsLembretes($contaId);
        }
        return $excluiu;
    }

    public function closerTemConta(int $vendedorId): bool
    {
        return $this->banco->linha('SELECT 1 FROM closer_usuarios WHERE vendedor_id=?', [$vendedorId]) !== null;
    }

    /** Só o último link de convite vale: apaga os não usados do closer e grava o novo. */
    public function trocarConvite(int $vendedorId, string $tokenHash, string $expiraEm): void
    {
        $this->banco->executar('DELETE FROM closer_convites WHERE vendedor_id=? AND usado_em IS NULL', [$vendedorId]);
        $this->banco->executar('INSERT INTO closer_convites (vendedor_id, token_hash, expira_em) VALUES (?,?,?)', [$vendedorId, $tokenHash, $expiraEm]);
    }

    /** A conta ATIVA (id, nome, e-mail), ou null. @return array<string, mixed>|null */
    public function contaAtiva(int $contaId): ?array
    {
        return $this->banco->linha("SELECT id, nome, email FROM closer_usuarios WHERE id=? AND status='ativo'", [$contaId]);
    }

    public function gravarTokenDeSenha(int $contaId, string $tokenHash, string $expiraEm): void
    {
        $this->banco->executar('UPDATE closer_usuarios SET reset_token_hash=?, reset_expira_em=?, reset_solicitado_em=NULL WHERE id=?', [$tokenHash, $expiraEm, $contaId]);
    }
}
