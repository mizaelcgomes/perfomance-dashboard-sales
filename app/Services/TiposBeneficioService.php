<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\TipoBeneficioRepository;

/**
 * Estilo de TODOS os benefícios (ativos ou não), na ordem final escolhida pelo admin: rótulo, cor, ícone e posição.
 * Reproduz getTiposEstilo e suas auxiliares do config.php atual (DUPLICAÇÃO TEMPORÁRIA: o config.php continua usando as dele
 * porque o Portal do Closer e o admin.php ainda as chamam). O resultado é o mesmo: o golden de get_relatorio_closers compara a lista "tipos".
 */
final class TiposBeneficioService
{
    /** Benefícios fixos do sistema (os personalizados vêm da tabela tipos_beneficio_custom). */
    public const FIXOS = [
        'bpc_loas' => 'BPC/LOAS',
        'sal_maternidade' => 'Salário Maternidade',
        'aux_acidente' => 'Auxílio Acidente',
        'aux_doenca' => 'Auxílio Doença',
        'outros' => 'Outros',
    ];

    /** Ícones que o admin pode escolher (só as chaves; o desenho em si fica no front-end). */
    public const ICONES = ['heart', 'activity', 'smile', 'users', 'file', 'shield', 'alert', 'sun', 'home', 'briefcase', 'star', 'award', 'clock', 'dollar', 'tag', 'umbrella', 'scale', 'zap', 'more'];

    private const FAMILIAS = [
        'bpc' => ['#60a5fa', '#38bdf8', '#3b82f6', '#818cf8'],
        'loas' => ['#60a5fa', '#38bdf8', '#3b82f6', '#818cf8'],
        'aposent' => ['#34d399', '#4ade80', '#10b981', '#2dd4bf', '#86efac', '#a3e635'],
        'acidente' => ['#fbbf24', '#f59e0b', '#facc15', '#fcd34d'],
        'doenc' => ['#fb7185', '#f87171', '#ef4444', '#f43f5e'],
        'matern' => ['#f472b6', '#ec4899', '#e879f9', '#f9a8d4'],
        'pens' => ['#a78bfa', '#c084fc', '#8b5cf6', '#d8b4fe'],
        'invalid' => ['#fb923c', '#f97316', '#fdba74', '#ea580c'],
        'rural' => ['#a3e635', '#84cc16', '#bef264', '#65a30d'],
        'outros' => ['#94a3b8'],
    ];
    private const CORES_GERAIS = ['#2dd4bf', '#38bdf8', '#c084fc', '#f97316', '#facc15', '#4ade80', '#e879f9', '#f87171', '#818cf8', '#22d3ee'];
    private const ICONE_PELO_NOME = ['bpc' => 'heart', 'loas' => 'heart', 'aposent' => 'sun', 'acidente' => 'alert', 'doenc' => 'activity', 'matern' => 'smile',
        'pens' => 'users', 'invalid' => 'shield', 'rural' => 'home', 'outros' => 'more'];

    public function __construct(private TipoBeneficioRepository $tipos, private ConfiguracaoRepository $config)
    {
    }

    /**
     * chave => [chave, label, fixo, id, ativo, cor, cor_escolhida, icone, icone_escolhido, ordem]. "Outros" é sempre a última.
     * @return array<string, array<string, mixed>>
     */
    public function estilo(): array
    {
        $base = $this->todosBase();
        $ordem = $this->ordenados($base);
        $usadas = [];
        foreach ($base as $b) {
            if ($b['cor_salva']) {
                $usadas[$b['cor_salva']] = true;
            }
        }

        $saida = [];
        $n = 0;
        foreach ($ordem as $chave) {
            $b = $base[$chave];
            $n++;
            $cor = $b['cor_salva'];
            if (!$cor) {
                $cor = self::cor($chave, $b['label'], $usadas);
                $usadas[$cor] = true;
            }
            $saida[$chave] = [
                'chave' => $chave, 'label' => $b['label'], 'fixo' => $b['fixo'], 'id' => $b['id'], 'ativo' => $b['ativo'],
                'cor' => $cor, 'cor_escolhida' => $b['cor_salva'] !== null,
                'icone' => $b['icone_salvo'] ?: self::iconePadrao($chave, $b['label']), 'icone_escolhido' => $b['icone_salvo'] !== null,
                'ordem' => $n,
            ];
        }
        return $saida;
    }

    /**
     * Cor de um benefício. Sem cor escolhida, o nome dá a "família" (BPC azul, Aposentadoria verde...) e cada família tem vários tons:
     * dois benefícios da mesma família recebem tons diferentes.
     * @param array<string, bool> $usadas
     */
    public static function cor(string $chave, string $label = '', array $usadas = []): string
    {
        $s = self::normalizar($chave . ' ' . $label);
        $lista = self::CORES_GERAIS;
        $familia = false;
        foreach (self::FAMILIAS as $prefixo => $tons) {
            if (strpos($s, $prefixo) !== false) {
                $lista = $tons;
                $familia = true;
                break;
            }
        }
        $inicio = $familia ? 0 : (int) sprintf('%u', crc32($chave)) % count($lista);   // família: começa pelo tom principal
        $total = count($lista);
        for ($i = 0; $i < $total; $i++) {
            $cor = $lista[($inicio + $i) % $total];
            if (empty($usadas[$cor])) {
                return $cor;
            }
        }
        return $lista[$inicio];
    }

    /** Ícone padrão de um benefício, pelo nome (quando o admin não escolheu um). */
    public static function iconePadrao(string $chave, string $label = ''): string
    {
        $s = self::normalizar($chave . ' ' . $label);
        foreach (self::ICONE_PELO_NOME as $prefixo => $icone) {
            if (strpos($s, $prefixo) !== false) {
                return $icone;
            }
        }
        return 'file';
    }

    /** minúsculas e sem acento (sem mbstring): só o necessário para reconhecer "doença", "pensão"... */
    private static function normalizar(string $texto): string
    {
        return strtr(strtolower($texto), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }

    public static function corValida($cor): ?string
    {
        return (is_string($cor) && preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) ? strtolower($cor) : null;
    }

    public static function iconeValido($icone): ?string
    {
        return (is_string($icone) && in_array($icone, self::ICONES, true)) ? $icone : null;
    }

    /**
     * Todos os benefícios (fixos + personalizados, ativos ou não) na sequência padrão: fixos, personalizados (sort_order, id) e "Outros".
     * @return array<string, array<string, mixed>>
     */
    private function todosBase(): array
    {
        $estiloFixos = json_decode((string) $this->config->valor('tipos_fixos_estilo', '{}'), true);
        $estiloFixos = is_array($estiloFixos) ? $estiloFixos : [];

        $fixo = static function (string $chave, string $label) use ($estiloFixos): array {
            return ['chave' => $chave, 'label' => $label, 'fixo' => true, 'id' => null, 'ativo' => true,
                'cor_salva' => self::corValida($estiloFixos[$chave]['cor'] ?? null), 'icone_salvo' => self::iconeValido($estiloFixos[$chave]['icone'] ?? null)];
        };

        $saida = [];
        foreach (self::FIXOS as $chave => $label) {
            if ($chave !== 'outros') {
                $saida[$chave] = $fixo($chave, $label);
            }
        }
        foreach ($this->tipos->personalizados() as $r) {
            $saida[$r['chave']] = ['chave' => $r['chave'], 'label' => $r['label'], 'fixo' => false, 'id' => (int) $r['id'], 'ativo' => (bool) $r['ativo'],
                'cor_salva' => self::corValida($r['cor']), 'icone_salvo' => self::iconeValido($r['icone'])];
        }
        $saida['outros'] = $fixo('outros', self::FIXOS['outros']);
        return $saida;
    }

    /**
     * Ordem final das chaves: a salva primeiro (só as que existem), depois as que faltam na sequência padrão; "Outros" sempre por último.
     * @param array<string, array<string, mixed>> $base
     * @return array<int, string>
     */
    private function ordenados(array $base): array
    {
        $salva = json_decode((string) $this->config->valor('tipos_ordem', '[]'), true);
        $salva = is_array($salva) ? array_values(array_filter($salva, 'is_string')) : [];

        $ordem = [];
        foreach ($salva as $chave) {
            if (isset($base[$chave]) && $chave !== 'outros' && !in_array($chave, $ordem, true)) {
                $ordem[] = $chave;
            }
        }
        foreach (array_keys($base) as $chave) {
            if ($chave !== 'outros' && !in_array($chave, $ordem, true)) {
                $ordem[] = $chave;
            }
        }
        $ordem[] = 'outros';
        return $ordem;
    }

    // ── Fase 5: benefícios ativos, ordem e estilo dos fixos (a tela de Contratos e a aba Tipos de Benefício) ────────────────

    /** chave => rótulo dos benefícios ATIVOS, na ordem escolhida pelo admin ("Outros" sempre por último). @return array<string, string> */
    public function ativos(): array
    {
        $base = $this->todosBase();
        $saida = [];
        foreach ($this->ordenados($base) as $chave) {
            if ($base[$chave]['ativo']) {
                $saida[$chave] = $base[$chave]['label'];
            }
        }
        return $saida;
    }

    /** chave => cor de todos os benefícios (ativos ou não). @return array<string, string> */
    public function cores(): array
    {
        $saida = [];
        foreach ($this->estilo() as $chave => $e) {
            $saida[$chave] = $e['cor'];
        }
        return $saida;
    }

    /** "Outros" (e "Outros: descrição") ficam sempre no fim das listas por quantidade. */
    public static function ehOutros(string $chave): bool
    {
        return substr($chave, 0, 6) === 'outros';
    }

    /** Rótulo de um benefício que já não está ativo (desativado ou removido), a partir da chave. */
    public static function rotuloDeInativo(string $chave): string
    {
        return ucfirst(str_replace('_', ' ', (string) preg_replace('/^custom_/', '', $chave)));
    }

    /** Coloca o benefício na posição $pos (1 = primeiro). "Outros" não se move: é sempre o último. */
    public function mover(string $chave, int $pos): void
    {
        if ($chave === 'outros') {
            return;
        }
        $ordem = array_values(array_filter($this->ordenados($this->todosBase()), static fn($k) => $k !== 'outros' && $k !== $chave));
        $pos = max(1, min(count($ordem) + 1, $pos));
        array_splice($ordem, $pos - 1, 0, [$chave]);
        $this->config->definir('tipos_ordem', (string) json_encode($ordem));
    }

    /** Tira o benefício da ordem salva (ao apagar um personalizado). */
    public function removerDaOrdem(string $chave): void
    {
        $salva = json_decode((string) $this->config->valor('tipos_ordem', '[]'), true);
        $salva = is_array($salva) ? array_values(array_filter($salva, 'is_string')) : [];
        $this->config->definir('tipos_ordem', (string) json_encode(array_values(array_filter($salva, static fn($k) => $k !== $chave))));
    }

    /** O estilo (cor e ícone) guardado dos benefícios fixos: chave => [cor, icone]. @return array<string, array<string, string>> */
    public function estiloDosFixos(): array
    {
        $j = json_decode((string) $this->config->valor('tipos_fixos_estilo', '{}'), true);
        return is_array($j) ? $j : [];
    }

    /** Grava o estilo dos fixos (vazio vira {} no JSON, como sempre foi). @param array<string, array<string, string>> $estilo */
    public function gravarEstiloDosFixos(array $estilo): void
    {
        $this->config->definir('tipos_fixos_estilo', (string) json_encode((object) $estilo));
    }
}
