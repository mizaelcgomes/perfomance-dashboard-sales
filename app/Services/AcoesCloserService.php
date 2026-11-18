<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Repositories\AcaoRepository;
use DateTimeImmutable;

/**
 * As ações do PRÓPRIO closer no portal (ligação, follow-up, lead quente): resumo do dia, histórico, registro, edição, exclusão e a evolução
 * dos últimos 14 dias. Porte fiel das ações resumo, acoes, acao_get, acao_criar, acao_editar, acao_excluir e evolucao do closer-api.php
 * (mesmas validações, na mesma ordem, e as mesmas mensagens). O closer (vendedor_id) vem SEMPRE da sessão: ninguém lê nem altera a ação de
 * outro closer, qualquer que seja o id enviado.
 *
 * Cada método devolve o corpo da resposta (sem o "success") ou ['erro' => mensagem, 'campo' => campo do formulário ou null, 'status' => HTTP].
 */
final class AcoesCloserService
{
    public function __construct(private AcaoRepository $acoes, private Calendario $calendario)
    {
    }

    /** As ações de hoje por tipo e o total. @return array{ligacao: int, followup: int, lead_quente: int, total: int} */
    public function resumoDoDia(int $vendedorId): array
    {
        $hoje = Relogio::hoje();
        $contagens = $this->acoes->contagens([$vendedorId], $hoje, $hoje)[$vendedorId];
        $contagens['total'] = array_sum($contagens);
        return $contagens;
    }

    /** @return array<string, mixed> */
    public function resumo(int $vendedorId): array
    {
        return ['hoje' => Relogio::hoje(), 'resumo' => $this->resumoDoDia($vendedorId)];
    }

    /**
     * O histórico do closer, do mais novo ao mais antigo, com filtros: tipo, periodo (hoje, ontem, 7d, 30d, semana, mes ou data), q (telefone ou
     * observação), limit (1 a 50) e offset.
     * @param array<string, mixed> $filtros
     * @return array<string, mixed>
     */
    public function listar(int $vendedorId, array $filtros): array
    {
        $condicoes = ['vendedor_id = ?'];
        $parametros = [$vendedorId];
        $tipo = (string) ($filtros['tipo'] ?? '');
        if (isset(ProdutividadeService::TIPOS_DE_ACAO[$tipo])) {
            $condicoes[] = 'tipo = ?';
            $parametros[] = $tipo;
        }
        $agora = Relogio::agora();
        $hoje = $agora->format('Y-m-d');
        $periodo = (string) ($filtros['periodo'] ?? 'hoje');
        $ini = $fim = null;
        if ($periodo === 'hoje') {
            $ini = $fim = $hoje;
        } elseif ($periodo === 'ontem') {
            $ini = $fim = $agora->modify('-1 day')->format('Y-m-d');
        } elseif ($periodo === '7d') {
            $ini = $agora->modify('-6 days')->format('Y-m-d');
            $fim = $hoje;
        } elseif ($periodo === '30d') {
            $ini = $agora->modify('-29 days')->format('Y-m-d');
            $fim = $hoje;
        } elseif ($periodo === 'semana') {
            $semana = $this->calendario->semanaAtual();   // a semana do Calendário do admin
            $ini = $semana['start'];
            $fim = $semana['end'];
        } elseif ($periodo === 'mes') {
            $ini = substr($hoje, 0, 8) . '01';
            $fim = $agora->format('Y-m-t');
        } elseif ($periodo === 'data' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filtros['data'] ?? '')) === 1) {
            $ini = $fim = (string) $filtros['data'];
        }
        if ($ini !== null) {
            $condicoes[] = 'registrado_em >= ? AND registrado_em <= ?';
            $parametros[] = "$ini 00:00:00";
            $parametros[] = "$fim 23:59:59";
        }
        $busca = Texto::limpar($filtros['q'] ?? '', 40);
        if ($busca !== '') {
            $digitos = preg_replace('/\D+/', '', $busca);
            if (strlen((string) $digitos) >= 3) {
                $condicoes[] = '(telefone LIKE ? OR observacao LIKE ?)';
                $parametros[] = "%$digitos%";
                $parametros[] = '%' . $busca . '%';
            } else {
                $condicoes[] = 'observacao LIKE ?';
                $parametros[] = '%' . $busca . '%';
            }
        }
        $limite = max(1, min(50, (int) ($filtros['limit'] ?? 30)));
        $deslocamento = max(0, (int) ($filtros['offset'] ?? 0));
        $resultado = $this->acoes->historico($condicoes, $parametros, $limite, $deslocamento);
        $linhas = $resultado['linhas'];
        $temMais = count($linhas) > $limite;
        if ($temMais) {
            array_pop($linhas);
        }
        return ['total' => $resultado['total'], 'has_more' => $temMais, 'hoje' => $hoje, 'acoes' => array_map([$this, 'linha'], $linhas)];
    }

    /** Uma ação do closer, com o telefone formatado e o momento no formato do campo de data e hora. @return array<string, mixed> */
    public function obter(int $vendedorId, int $id): array
    {
        $r = $this->acoes->doCloser($id, $vendedorId);
        if ($r === null) {
            return $this->erro('Ação não encontrada.', null, 404);
        }
        return ['acao' => $this->linha($r) + ['telefone' => Telefone::formatar((string) $r['telefone']), 'dt_local' => str_replace(' ', 'T', substr((string) $r['registrado_em'], 0, 16))]];
    }

    /** @param array<string, mixed> $dados telefone, tipo, observacao e registrado_em (opcional: sem ele vale agora) @return array<string, mixed> */
    public function criar(int $vendedorId, array $dados): array
    {
        $telefone = Telefone::digitos($dados['telefone'] ?? '');
        if (!Telefone::valido($telefone)) {
            return $this->erro('Telefone inválido. Use DDD + número, ex.: (11) 98765-4321.', 'telefone');
        }
        $tipo = (string) ($dados['tipo'] ?? '');
        if (!isset(ProdutividadeService::TIPOS_DE_ACAO[$tipo])) {
            return $this->erro('Escolha o tipo da ação.', 'tipo');
        }
        $observacao = Texto::limpar($dados['observacao'] ?? '', 255);
        $momento = Relogio::agora()->format('Y-m-d H:i:s');
        if (!empty($dados['registrado_em'])) {
            $momento = self::momento($dados['registrado_em']);
            if ($momento === null) {
                return $this->erro('Data/hora inválida.', 'registrado_em');
            }
            if (($falha = $this->conferirMomento($momento)) !== null) {
                return $falha;
            }
        }
        // Cada tentativa é registrada, sem trava por número: o histórico completo fica para auditoria e contexto.
        $id = $this->acoes->criar($vendedorId, $tipo, $telefone, $observacao !== '' ? $observacao : null, $momento);
        return ['message' => 'Ação registrada!', 'acao' => $this->linha((array) $this->acoes->doCloser($id, $vendedorId)), 'resumo' => $this->resumoDoDia($vendedorId)];
    }

    /** @param array<string, mixed> $dados id, telefone, tipo, observacao e registrado_em @return array<string, mixed> */
    public function editar(int $vendedorId, array $dados): array
    {
        $id = (int) ($dados['id'] ?? 0);
        if ($this->acoes->doCloser($id, $vendedorId) === null) {
            return $this->erro('Ação não encontrada.', null, 404);
        }
        $telefone = Telefone::digitos($dados['telefone'] ?? '');
        if (!Telefone::valido($telefone)) {
            return $this->erro('Telefone inválido. Use DDD + número, ex.: (11) 98765-4321.', 'telefone');
        }
        $tipo = (string) ($dados['tipo'] ?? '');
        if (!isset(ProdutividadeService::TIPOS_DE_ACAO[$tipo])) {
            return $this->erro('Escolha o tipo da ação.', 'tipo');
        }
        $observacao = Texto::limpar($dados['observacao'] ?? '', 255);
        $momento = self::momento($dados['registrado_em'] ?? '');
        if ($momento === null) {
            return $this->erro('Data/hora inválida.', 'registrado_em');
        }
        if (($falha = $this->conferirMomento($momento)) !== null) {
            return $falha;
        }
        $this->acoes->atualizar($id, $vendedorId, $tipo, $telefone, $observacao !== '' ? $observacao : null, $momento);
        return ['message' => 'Ação atualizada.', 'resumo' => $this->resumoDoDia($vendedorId)];
    }

    /** @return array<string, mixed> */
    public function excluir(int $vendedorId, int $id): array
    {
        if ($this->acoes->excluir($id, $vendedorId) === 0) {
            return $this->erro('Ação não encontrada.', null, 404);
        }
        return ['message' => 'Ação excluída.', 'resumo' => $this->resumoDoDia($vendedorId)];
    }

    /** As ações dos últimos 7 dias (dia a dia), o total e o total dos 7 dias anteriores. @return array<string, mixed> */
    public function evolucao(int $vendedorId): array
    {
        $agora = Relogio::agora();
        $hoje = $agora->format('Y-m-d');
        $porDia = $this->acoes->totaisPorDia([$vendedorId], $agora->modify('-13 days')->format('Y-m-d'), $hoje);
        $dias = [];
        $total = 0;
        $anterior = 0;
        for ($i = 13; $i >= 0; $i--) {
            $data = $agora->modify("-$i days")->format('Y-m-d');
            $n = $porDia[$data] ?? 0;
            if ($i <= 6) {
                $dias[] = ['data' => $data, 'total' => $n];
                $total += $n;
            } else {
                $anterior += $n;
            }
        }
        return ['dias' => $dias, 'total' => $total, 'anterior' => $anterior];
    }

    // ── apoio ────────────────────────────────────────────────────────────────────────────────────

    /** Uma ação como a tela mostra: a data e a hora separadas e o telefone mascarado (o número inteiro só aparece ao abrir a ação). @param array<string, mixed> $r @return array<string, mixed> */
    private function linha(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'tipo' => $r['tipo'], 'tipo_label' => ProdutividadeService::TIPOS_DE_ACAO[$r['tipo']] ?? $r['tipo'],
            'data' => substr((string) $r['registrado_em'], 0, 10), 'hora' => substr((string) $r['registrado_em'], 11, 5),
            'mascarado' => Telefone::mascarar((string) $r['telefone']), 'observacao' => $r['observacao'], 'registrado_em' => $r['registrado_em'],
        ];
    }

    /** 'AAAA-MM-DDTHH:MM' ou 'AAAA-MM-DD HH:MM[:SS]' vira 'AAAA-MM-DD HH:MM:SS'; null se não for uma data e hora válidas. @param mixed $valor */
    private static function momento($valor): ?string
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $formato) {
            $d = DateTimeImmutable::createFromFormat($formato, $texto, Relogio::fuso());
            if ($d !== false && $d->format($formato) === $texto) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    /** O momento de uma ação não pode estar no futuro (com 5 minutos de folga) nem ter mais de 90 dias. @return array<string, mixed>|null */
    private function conferirMomento(string $momento): ?array
    {
        $agora = Relogio::agora()->getTimestamp();
        $instante = (new DateTimeImmutable($momento, Relogio::fuso()))->getTimestamp();   // no fuso do sistema, não no padrão do PHP
        if ($instante > $agora + 300) {
            return $this->erro('A data/hora não pode estar no futuro.', 'registrado_em');
        }
        if ($instante < $agora - 90 * 86400) {
            return $this->erro('Só é possível registrar ações dos últimos 90 dias.', 'registrado_em');
        }
        return null;
    }

    /** @return array{erro: string, campo: ?string, status: int} */
    private function erro(string $mensagem, ?string $campo = null, int $status = 200): array
    {
        return ['erro' => $mensagem, 'campo' => $campo, 'status' => $status];
    }
}
