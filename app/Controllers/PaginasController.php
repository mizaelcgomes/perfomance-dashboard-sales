<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Relogio;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ContaCloserRepository;
use App\Services\AutenticacaoAdminService;
use App\Services\AutenticacaoCloserService;
use App\Services\IconesDeBeneficio;
use App\Services\IconesPortal;
use App\Services\LiderEscopoService;
use App\Services\TiposBeneficioService;

/**
 * As páginas do site (HTML): login e painel do admin, portal do closer (entrar, cadastrar, recuperar senha e o portal), portal do líder e a saída.
 * Eram as cascas login.php, admin.php, closer*.php, lider.php e logout.php (fase 9b). A TV é a TvController::pagina, na raiz.
 */
final class PaginasController
{
    private const MENU_ICONES = ['phone', 'refresh', 'flame', 'inbox', 'x', 'plus', 'alert', 'check', 'clock', 'trophy'];

    public function __construct(
        private View $view,
        private AutenticacaoAdminService $admin,
        private AutenticacaoCloserService $closer,
        private LiderEscopoService $lideres,
        private ContaCloserRepository $contas,
        private TiposBeneficioService $tipos,
        private ConfiguracaoRepository $config
    ) {
    }

    // ── admin ────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $p */
    public function adminEntrar(Request $r, array $p = []): Response
    {
        if ($this->admin->logado()) {
            return Response::redirecionar('/admin');
        }
        return Response::html($this->view->parcial('admin/login', ['logo' => $this->logo(), 'rodape' => $this->rodape()]));
    }

    /** @param array<string, string> $p */
    public function adminSair(Request $r, array $p = []): Response
    {
        $this->admin->sair();
        return Response::redirecionar('/');
    }

    /** @param array<string, string> $p */
    public function admin(Request $r, array $p = []): Response
    {
        if (!$this->admin->logado()) {
            return Response::redirecionar('/login');
        }
        $usuario = (string) Session::obter('admin_user', '');
        $inicial = strtoupper(substr($usuario, 0, 1));
        $estilo = $this->tipos->estilo();
        $dias = $this->config->diasDaSemana();
        $quarta = $this->quartaDaSemana();
        $datas = [];
        $rotulos = [];
        for ($i = 0; $i < $dias; $i++) {
            $datas[] = $quarta->modify('+' . $i . ' days')->format('d');
            $rotulos[] = ['Qua', 'Qui', 'Sex', 'Sáb', 'Dom', 'Seg', 'Ter'][$i % 7];
        }
        $dow = (int) Relogio::agora()->format('N');
        $indice = ($dow >= 3) ? $dow - 3 : $dow + 4;
        $agora = Relogio::agora();
        $boot = [
            'tipos' => array_keys($this->tipos->ativos()), 'tipoLabels' => array_values($this->tipos->ativos()),
            'tipoCores' => array_map(static fn (array $e): string => (string) $e['cor'], $estilo),
            'tipoIcons' => array_map(static fn (array $e): string => (string) $e['icone'], $estilo),
            'tipoIcones' => IconesDeBeneficio::CATALOGO,
            'weekDates' => $datas, 'weekLabels' => $rotulos, 'todayIdx' => min($indice, $dias - 1), 'weekDayCount' => $dias,
            'curMonth' => (int) $agora->format('n'), 'curYear' => (int) $agora->format('Y'),
            'inicialHtml' => htmlspecialchars($inicial), 'usuarioHtml' => htmlspecialchars($usuario),
        ];
        return Response::html(
            $this->view->parcial('layouts/admin', ['csrf' => Csrf::token(), 'adminUser' => $usuario, 'adminInitial' => $inicial, 'boot' => $boot]),
            200,
            $this->cabecalhosDoPortal()
        );
    }

    // ── portal do closer: páginas públicas ───────────────────────────────────────────────

    /** @param array<string, string> $p */
    public function closerEntrar(Request $r, array $p = []): Response
    {
        return $this->paginaPublica($r, 'closer/login', ['senhaRedefinida' => $r->query('ok') === 'senha']);
    }

    /** @param array<string, string> $p */
    public function closerCadastrar(Request $r, array $p = []): Response
    {
        return $this->paginaPublica($r, 'closer/cadastro', $this->closer->paginaDeCadastro((string) $r->query('convite', '')));
    }

    /** @param array<string, string> $p */
    public function closerRecuperar(Request $r, array $p = []): Response
    {
        return $this->paginaPublica($r, 'closer/recuperar', ['aba' => $r->query('tab') === 'token' ? 'token' : 'pedir']);
    }

    // ── portal do closer e do líder ──────────────────────────────────────────────────────

    /** @param array<string, string> $p */
    public function closerPortal(Request $r, array $p = []): Response
    {
        $conta = $this->closer->contaLogada();
        if ($conta === null) {
            return Response::redirecionar('/closer/login');
        }
        if ($this->lideres->equipeDoLider((int) $conta['id']) !== null) {   // quem lidera uma equipe usa o portal do líder
            return Response::redirecionar('/lider');
        }
        $boot = $this->bootDoPortal($conta) + ['papel' => 'closer', 'lider' => $this->contas->nomeDoLider((int) $conta['vendedor_id'])];
        $boot['tipoIc'] = IconesDeBeneficio::CATALOGO;
        return $this->portal('closer/portal', $boot);
    }

    /** @param array<string, string> $p */
    public function liderPortal(Request $r, array $p = []): Response
    {
        $conta = $this->closer->contaLogada();
        if ($conta === null) {
            return Response::redirecionar('/closer/login');
        }
        if ($this->lideres->equipeDoLider((int) $conta['id']) === null) {
            return Response::redirecionar('/closer');
        }
        $boot = $this->bootDoPortal($conta);
        $boot['tipoIc'] = IconesDeBeneficio::CATALOGO;
        return $this->portal('lider/portal', $boot);
    }

    // ── apoio ────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $conta @return array<string, mixed> */
    private function bootDoPortal(array $conta): array
    {
        $nome = (string) ($conta['v_nome'] ?: $conta['nome']);
        return [
            'nome' => $nome,
            'primeiro' => explode(' ', trim($nome))[0],
            'equipe' => $conta['equipe_nome'] ?: 'Sem equipe',
            'cor' => preg_match('/^#[0-9a-f]{6}$/i', (string) $conta['equipe_cor']) ? $conta['equipe_cor'] : '#d4af37',
            'hoje' => Relogio::hoje(),
            'csrf' => Csrf::tokenCloser(),
            'ic' => IconesPortal::conjunto(self::MENU_ICONES),
        ];
    }

    /** @param array<string, mixed> $boot */
    private function portal(string $view, array $boot): Response
    {
        $bootJson = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return Response::html($this->view->parcial($view, ['boot' => $boot, 'bootJson' => $bootJson]), 200, $this->cabecalhosDoPortal());
    }

    /** Página pública do portal: quem já está logado vai direto ao portal. @param array<string, mixed> $extra */
    private function paginaPublica(Request $r, string $view, array $extra): Response
    {
        if ($this->closer->contaLogada() !== null) {
            return Response::redirecionar('/closer');
        }
        return Response::html(
            $this->view->parcial($view, ['csrf' => $this->closer->tokenCsrf(), 'logo' => $this->logo(), 'rodape' => $this->rodape()] + $extra),
            200,
            $this->cabecalhosDoPortal()
        );
    }

    /** Sem cache (dados pessoais), sem iframe (clickjacking) e sem Referer (o link de convite leva um token na URL). @return array<string, string> */
    private function cabecalhosDoPortal(): array
    {
        return ['Cache-Control' => 'no-store', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'no-referrer'];
    }

    /** Meia-noite da quarta-feira que abre a semana de hoje (a semana vai de quarta a terça). */
    private function quartaDaSemana(): \DateTimeImmutable
    {
        $agora = Relogio::agora();
        $dow = (int) $agora->format('N');
        return $agora->modify('-' . (($dow >= 3) ? $dow - 3 : $dow + 4) . ' days');
    }

    /** O logo (logo.svg, .png, .webp ou .jpg na pasta pública), ou a marca em texto. */
    private function logo(): string
    {
        foreach (['logo.svg', 'logo.png', 'logo.webp', 'logo.jpg'] as $f) {
            $caminho = Config::publico() . '/' . $f;
            if (is_file($caminho)) {
                return '<div class="brand"><img class="brand-img" src="/' . htmlspecialchars($f) . '?v=' . (int) @filemtime($caminho) . '" alt="TOP CLOSERS"></div>';
            }
        }
        return '<div class="brand" role="img" aria-label="TOP CLOSERS"><span class="brand-name" aria-hidden="true">Top Closers</span></div>';
    }

    private function rodape(): string
    {
        return $this->view->parcial('partials/rodape-site');
    }
}
