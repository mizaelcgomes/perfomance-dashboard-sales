<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Csrf;
use App\Core\Relogio;
use App\Core\Session;
use App\Repositories\ContaCloserRepository;
use DateTimeImmutable;
use PDOException;

/**
 * Entrada no portal do closer: cadastro (conta PENDENTE, o admin aprova), login (com "lembrar de mim" por 7 dias), pedido e uso do token de
 * redefinição de senha (SEM e-mail: o admin entrega o token), logout e a conta logada. Porte fiel do closer-lib.php e das ações register, login,
 * forgot, reset e logout do closer-api.php: mesmas validações, na mesma ordem, mesmas mensagens e mesmos limites de tentativas.
 *
 * Sessão: a chave 'closer_id' (separada do 'admin_logged': estar logado como closer nunca dá acesso ao admin, nem o contrário) e o token
 * 'cr_csrf'. A conta é revalidada A CADA requisição (contaLogada): revogar, arquivar ou ocultar a equipe vale na hora.
 *
 * Cada ação devolve o corpo de sucesso (sem o "success") ou ['erro' => mensagem, 'campo' => campo do formulário ou null, 'status' => HTTP, 'code' => código
 * que a tela usa para escolher o aviso (opcional)].
 */
final class AutenticacaoCloserService
{
    private const DIAS_LEMBRAR = 7;
    private const COOKIE = 'closer_rm';
    // Hash fixo usado quando o e-mail não existe, para o login levar o mesmo tempo (não revela se a conta existe)
    private const HASH_FALSO = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    /** @var array<string, mixed>|null|false false = ainda não resolvida nesta requisição */
    private $conta = false;

    public function __construct(private ContaCloserRepository $contas, private LimiteDeTentativas $limite)
    {
    }

    // ── Conta logada ─────────────────────────────────────────────────────────────────────────────────────

    /** Conta ativa, vinculada a um closer não arquivado e de equipe que não está oculta da TV (equipe oculta não tem portal). @param array<string, mixed> $c */
    public static function podeEntrar(array $c): bool
    {
        return $c['status'] === 'ativo' && !empty($c['vendedor_id']) && $c['v_deleted'] === null && empty($c['equipe_oculta']);
    }

    /** A conta logada (sessão ou cookie "lembrar de mim"), revalidada agora, ou null. @return array<string, mixed>|null */
    public function contaLogada(): ?array
    {
        if ($this->conta !== false) {
            return $this->conta;
        }
        $c = null;
        $id = (int) Session::obter('closer_id', 0);
        if ($id > 0) {
            $c = $this->contas->porId($id);
        }
        if ($c !== null && !self::podeEntrar($c)) {
            Session::esquecer('closer_id');
            $this->esquecerLembrete((int) $c['id']);
            $c = null;
        }
        if ($c === null && $id === 0) {
            $c = $this->contaDoLembrete();
            if ($c !== null) {
                Session::definir('closer_id', (int) $c['id']);
            }
        }
        return $this->conta = $c;
    }

    /** O token CSRF do portal (o mesmo cr_csrf de sempre). */
    public function tokenCsrf(): string
    {
        return Csrf::tokenCloser();
    }

    // ── Cadastro ─────────────────────────────────────────────────────────────────────────────────────────

    /** O que a página de cadastro mostra: as equipes e o convite (se veio token na URL e ele vale). @return array<string, mixed> */
    public function paginaDeCadastro(string $tokenDaUrl): array
    {
        $token = preg_match('/^[0-9a-f]{40}$/', $tokenDaUrl) ? $tokenDaUrl : '';
        $convite = $token !== '' ? $this->convite($token) : null;
        return ['equipes' => $this->contas->equipesPublicas(), 'token' => $token, 'convite' => $convite, 'conviteInvalido' => $token !== '' && $convite === null];
    }

    /** @return array<string, mixed>|null */
    private function convite(string $token): ?array
    {
        return preg_match('/^[0-9a-f]{40}$/', $token) ? $this->contas->conviteValido(hash('sha256', $token), $this->agora()) : null;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function registrar(array $in, string $ip): array
    {
        if ($this->limite->bloqueado('reg', $ip, 10, 3600) > 0) {
            return $this->erro('Muitos cadastros deste computador. Tente novamente mais tarde.', null, 429);
        }
        $nome = Texto::limpar($in['nome'] ?? '', 100);
        $email = self::normalizarEmail($in['email'] ?? '');
        $equipeId = (int) ($in['equipe_id'] ?? 0);
        $token = trim((string) ($in['convite'] ?? ''));
        if (Texto::tamanho($nome) < 3) {
            return $this->erro('Informe seu nome completo.', 'nome');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            return $this->erro('Informe um e-mail válido.', 'email');
        }
        if (($e = $this->senhaInvalida($in)) !== null) {
            return $e;
        }
        if (!hash_equals((string) $in['senha'], (string) ($in['senha2'] ?? ''))) {
            return $this->erro('As senhas não conferem.', 'senha2');
        }

        $vendedorId = null;
        if ($token !== '') {
            $convite = $this->convite($token);
            if ($convite === null) {
                return $this->erro('Este convite é inválido ou expirou. Peça um novo link ao administrador.', 'convite');
            }
            $vendedorId = (int) $convite['vendedor_id'];
            $equipeId = (int) $convite['equipe_id'];
            $vinculo = 'convite';
        } else {
            $vinculo = 'nenhum';
            if (!$this->contas->equipeExiste($equipeId)) {
                return $this->erro('Selecione sua equipe.', 'equipe_id');
            }
            // Vínculo automático: mesmo nome (sem acento/caixa) dentro da equipe, entre quem ainda não tem conta. Só vincula se houver EXATAMENTE
            // um candidato; o admin confere e pode trocar ao aprovar.
            $alvo = self::normalizarNome($nome);
            $achados = [];
            foreach ($this->contas->closersSemContaDaEquipe($equipeId) as $v) {
                if (self::normalizarNome((string) $v['nome']) === $alvo) {
                    $achados[] = (int) $v['id'];
                }
            }
            if (count($achados) === 1) {
                $vendedorId = $achados[0];
                $vinculo = 'nome';
            }
        }

        $this->limite->falhou('reg', $ip, 3600);   // conta só tentativas que passaram da validação (inclui sondagem de e-mails)
        $jaTem = $this->erro('Este e-mail já tem cadastro. Entre ou recupere sua senha.', 'email', 409) + ['code' => 'email_existe'];
        if ($this->contas->idPorEmail($email) !== null) {
            return $jaTem;
        }
        try {
            $this->contas->criarPendente($vendedorId, $equipeId > 0 ? $equipeId : null, $nome, $email, password_hash((string) $in['senha'], PASSWORD_DEFAULT), $this->agora(), $token !== '' ? hash('sha256', $token) : null);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $jaTem;
            }
            throw $e;
        }
        return ['message' => 'Cadastro realizado! Aguarde a aprovação do administrador.', 'vinculo' => $vinculo];
    }

    // ── Login e logout ───────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function entrar(array $in, string $ip): array
    {
        $email = self::normalizarEmail($in['email'] ?? '');
        $senha = (string) ($in['senha'] ?? '');
        $chaveUsuario = $ip . '|' . $email;
        $espera = max($this->limite->bloqueado('login', $chaveUsuario, 5, 900), $this->limite->bloqueado('login_ip', $ip, 30, 900));
        if ($espera > 0) {
            return $this->erro(LimiteDeTentativas::mensagem($espera), null, 429);
        }
        if ($email === '' || $senha === '') {
            return $this->erro('Informe e-mail e senha.');
        }

        $id = $this->contas->idPorEmail($email);
        $c = $id !== null ? $this->contas->porId($id) : null;
        $senhaOk = password_verify($senha, $c !== null ? (string) $c['senha_hash'] : self::HASH_FALSO) && $c !== null;
        if (!$senhaOk) {
            $this->limite->falhou('login', $chaveUsuario, 900);
            $this->limite->falhou('login_ip', $ip, 900);
            return $this->erro('E-mail ou senha incorretos.');
        }
        $this->limite->limpar('login', $chaveUsuario);
        if ($c['status'] === 'pendente') {
            return $this->erro('Aguardando aprovação do administrador.') + ['code' => 'pendente'];
        }
        if ($c['status'] === 'rejeitado') {
            return $this->erro('Acesso negado. Entre em contato com o administrador.') + ['code' => 'rejeitado'];
        }
        if (!empty($c['equipe_oculta'])) {
            return $this->erro('Este acesso ao portal não está disponível para a sua equipe. Fale com o administrador.') + ['code' => 'equipe_oculta'];
        }
        if (!self::podeEntrar($c)) {
            return $this->erro('Sua conta de closer está desativada. Fale com o administrador.') + ['code' => 'desativado'];
        }

        $this->iniciarSessao($c);
        if (!empty($in['lembrar'])) {
            $this->emitirLembrete((int) $c['id']);
        }
        return ['redirect' => '/closer'];
    }

    /** @return array<string, mixed> */
    public function sair(): array
    {
        $c = $this->contaLogada();
        $this->esquecerLembrete($c !== null ? (int) $c['id'] : null);
        Session::esquecer('closer_id');
        Session::esquecer('cr_csrf');
        $this->conta = false;
        return ['redirect' => '/closer/login'];
    }

    // ── Recuperação de senha (sem e-mail) ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function pedirRedefinicao(array $in, string $ip): array
    {
        $email = self::normalizarEmail($in['email'] ?? '');
        if ($this->limite->bloqueado('forgot', $ip, 5, 3600) > 0) {
            return $this->erro('Muitos pedidos. Tente novamente mais tarde.', null, 429);
        }
        $this->limite->falhou('forgot', $ip, 3600);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->erro('Informe um e-mail válido.', 'email');
        }
        // Resposta sempre igual: não revela se o e-mail tem cadastro. O admin vê o pedido no card do closer.
        $this->contas->marcarPedidoDeRedefinicao($email, $this->agora());
        return ['message' => 'Pedido enviado ao administrador. Quando ele passar o seu token, use-o na tela de redefinição.'];
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function redefinir(array $in): array
    {
        $email = self::normalizarEmail($in['email'] ?? '');
        $token = self::normalizarToken($in['token'] ?? '');
        $espera = $this->limite->bloqueado('reset', $email, 5, 900);
        if ($espera > 0) {
            return $this->erro(LimiteDeTentativas::mensagem($espera), null, 429);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->erro('Informe o e-mail do cadastro.', 'email');
        }
        if ($token === '') {
            return $this->erro('Informe o token que o administrador enviou.', 'token');
        }
        if (($e = $this->senhaInvalida($in)) !== null) {
            return $e;
        }
        if (!hash_equals((string) $in['senha'], (string) ($in['senha2'] ?? ''))) {
            return $this->erro('As senhas não conferem.', 'senha2');
        }

        $d = $this->contas->dadosDeRedefinicao($email);
        $valido = $d !== null && $d['reset_token_hash'] && $d['reset_expira_em'] && $d['reset_expira_em'] > $this->agora()
            && hash_equals((string) $d['reset_token_hash'], hash('sha256', $token));
        if (!$valido) {
            $this->limite->falhou('reset', $email, 900);
            return $this->erro('Token inválido ou expirado. Peça um novo ao administrador.', 'token');
        }
        $this->contas->redefinirSenha((int) $d['id'], password_hash((string) $in['senha'], PASSWORD_DEFAULT));
        $this->limite->limpar('reset', $email);
        return ['message' => 'Senha redefinida! Entre com a nova senha.', 'redirect' => '/closer/login?ok=senha'];
    }

    // ── Textos ───────────────────────────────────────────────────────────────────────────────────────────

    /** @param mixed $valor */
    public static function normalizarEmail($valor): string
    {
        return strtolower(trim((string) $valor));
    }

    /** Token de redefinição sem hífen e em maiúsculas (aceita XXXXX-XXXXX, com espaços ou minúsculas). @param mixed $valor */
    public static function normalizarToken($valor): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $valor));
    }

    /** Minúsculas, sem acento e com espaços simples: para comparar o nome digitado com o do cadastro. */
    public static function normalizarNome(string $nome): string
    {
        $s = strtr(strtolower(trim($nome)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
            'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ç' => 'c', 'Ñ' => 'n',
        ]);
        return (string) preg_replace('/\s+/', ' ', $s);
    }

    /** @param array<string, mixed> $in @return array<string, mixed>|null o erro, ou null se a senha serve */
    private function senhaInvalida(array $in): ?array
    {
        $s = (string) ($in['senha'] ?? '');
        if (strlen($s) < 8) {
            return $this->erro('A senha precisa ter pelo menos 8 caracteres.', 'senha');
        }
        if (strlen($s) > 72) {
            return $this->erro('A senha pode ter no máximo 72 caracteres.', 'senha');
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function erro(string $mensagem, ?string $campo = null, int $status = 200): array
    {
        return ['erro' => $mensagem, 'campo' => $campo, 'status' => $status];
    }

    private function agora(): string
    {
        return Relogio::agora()->format('Y-m-d H:i:s');
    }

    // ── Sessão e "lembrar de mim" ────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $c */
    private function iniciarSessao(array $c): void
    {
        Session::regenerar();
        Session::definir('closer_id', (int) $c['id']);
        Session::esquecer('cr_csrf');   // token novo depois do login
        $this->contas->registrarLogin((int) $c['id'], $this->agora());
        $this->conta = false;
    }

    private function emitirLembrete(int $contaId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expira = time() + self::DIAS_LEMBRAR * 86400;
        $expiraEm = (new DateTimeImmutable('@' . $expira))->setTimezone(Relogio::fuso())->format('Y-m-d H:i:s');
        $this->contas->guardarLembrete($contaId, $selector, hash('sha256', $validator), $expiraEm, $this->agora());
        $this->cookie($selector . '.' . $validator, $expira);
    }

    private function esquecerLembrete(?int $contaId): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($cookie) && strpos($cookie, '.') !== false) {
            $this->contas->apagarLembretePorSelector(explode('.', $cookie, 2)[0]);
        }
        if ($contaId) {
            $this->contas->esquecerTodosOsLembretes($contaId);
        }
        $this->cookie('', time() - 3600);
    }

    /** Cookie "lembrar de mim" → conta (e gira o token a cada uso). null se inválido ou expirado. @return array<string, mixed>|null */
    private function contaDoLembrete(): ?array
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || strpos($cookie, '.') === false) {
            return null;
        }
        [$selector, $validator] = explode('.', $cookie, 2);
        if (!preg_match('/^[0-9a-f]{24}$/', $selector) || !preg_match('/^[0-9a-f]{64}$/', $validator)) {
            return null;
        }
        $l = $this->contas->lembretePorSelector($selector, $this->agora());
        if ($l === null || !hash_equals((string) $l['validator_hash'], hash('sha256', $validator))) {
            if ($l !== null) {
                $this->esquecerLembrete((int) $l['usuario_id']);
            }
            return null;
        }
        $c = $this->contas->porId((int) $l['usuario_id']);
        $this->contas->apagarLembrete((int) $l['id']);
        if ($c === null || !self::podeEntrar($c)) {
            return null;
        }
        $this->emitirLembrete((int) $c['id']);
        return $c;
    }

    /** Envia o cookie (HttpOnly, SameSite=Lax, Secure no HTTPS). Em linha de comando (testes) ou com a saída já iniciada, não faz nada. */
    private function cookie(string $valor, int $expira): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $valor, ['expires' => $expira, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    }
}
