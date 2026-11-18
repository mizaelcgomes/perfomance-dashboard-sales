<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\ReuniaoRepository;
use App\Repositories\VendedorRepository;

/**
 * Reuniões da liderança. Estados: agendada → realizada (com o horário em que foi feita) ou cancelada.
 *  - agendada: edita título e horário marcado; pode ser realizada ou cancelada;
 *  - realizada: edita o título e corrige o horário realizado (nunca antes do marcado, nunca no futuro);
 *  - cancelada: não muda mais.
 * Toda operação recebe a equipe do líder (vinda do middleware): uma reunião de outra equipe nunca é encontrada.
 */
final class ReuniaoService
{
    private const LIMITE_LISTA = 200;

    public function __construct(private ReuniaoRepository $reunioes, private VendedorRepository $vendedores)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        return $this->itens(array_slice($this->reunioes->listar($equipeId), 0, self::LIMITE_LISTA));
    }

    /** Reuniões da equipe com horário marcado entre as duas datas e horas (para a agenda). @return array<int, array<string, mixed>> */
    public function entre(int $equipeId, string $de, string $ate): array
    {
        return $this->itens($this->reunioes->entre($equipeId, $de, $ate));
    }

    /** @param array<int, array<string, mixed>> $linhas @return array<int, array<string, mixed>> */
    private function itens(array $linhas): array
    {
        $part = $this->reunioes->participantesDe(array_map(static fn(array $r): int => (int) $r['id'], $linhas));
        return array_map(fn(array $r): array => $this->item($r, $part[(int) $r['id']] ?? []), $linhas);
    }

    /**
     * Confere a lista de participantes. null = não informada (na criação vale a equipe toda menos o líder; na edição nada muda).
     * @param mixed $entrada
     * @param array<string, string> $erros
     * @return array<int, int>|null
     */
    private function participantesValidos(int $equipeId, ?int $liderVendedorId, $entrada, array &$erros): ?array
    {
        $time = array_map(static fn(array $v): int => $v['id'], $this->vendedores->doTime($equipeId));
        if ($entrada === null) {
            return $liderVendedorId === null ? null : array_values(array_diff($time, [$liderVendedorId]));
        }
        if (!is_array($entrada)) {
            $erros['participantes'] = 'Escolha os participantes da lista.';
            return null;
        }
        $ids = [];
        foreach ($entrada as $v) {
            $n = is_numeric($v) ? (int) $v : 0;
            if (!in_array($n, $time, true)) {
                $erros['participantes'] = 'A lista tem alguém que não é da sua equipe.';
                return null;
            }
            $ids[$n] = $n;
        }
        return array_values($ids);
    }

    /** @param mixed $participantes ids dos participantes (da equipe); sem lista, vale a equipe toda menos o líder */
    public function criar(int $equipeId, int $liderVendedorId, $titulo, $marcada, $participantes = null): Resultado
    {
        $erros = [];
        $t = RegistroValidador::texto($titulo, 120);
        if ($t === null || $t === '') {
            $erros['titulo'] = $t === null ? 'O título pode ter até 120 caracteres.' : 'Informe o título da reunião.';
        }
        $m = RegistroValidador::dataHora($marcada);
        if ($m === null || !RegistroValidador::naJanela($m)) {
            $erros['data_hora_marcada'] = 'Informe uma data e um horário válidos para a reunião.';
        }
        $ids = $this->participantesValidos($equipeId, $liderVendedorId, $participantes, $erros);
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $id = $this->reunioes->inserir($equipeId, (string) $t, (string) $m, $liderVendedorId, Relogio::agora()->format('Y-m-d H:i:s'));
        $this->reunioes->definirParticipantes($id, $ids ?? []);
        return Resultado::ok(['item' => $this->item($this->reunioes->buscar($id, $equipeId) ?? [])], 'Reunião marcada.');
    }

    /** Edita o que o estado permite. Campos que não vierem (null) ficam como estão. */
    /** @param mixed $participantes lista nova (null = não mexe nos participantes) */
    public function editar(int $equipeId, int $id, $titulo, $marcada, $realizada, $participantes = null): Resultado
    {
        $r = $this->reunioes->buscar($id, $equipeId);
        if ($r === null) {
            return Resultado::falha('Reunião não encontrada.');
        }
        if ($r['status'] === 'cancelada') {
            return Resultado::falha('Uma reunião cancelada não pode ser alterada.');
        }
        $erros = [];
        $t = $titulo === null ? (string) $r['titulo'] : RegistroValidador::texto($titulo, 120);
        if ($t === null || $t === '') {
            $erros['titulo'] = $t === null ? 'O título pode ter até 120 caracteres.' : 'Informe o título da reunião.';
        }
        $m = (string) $r['data_hora_marcada'];
        if ($marcada !== null && $marcada !== '') {
            if ($r['status'] !== 'agendada') {
                $erros['data_hora_marcada'] = 'O horário marcado só muda enquanto a reunião está agendada.';
            } else {
                $novo = RegistroValidador::dataHora($marcada);
                if ($novo === null || !RegistroValidador::naJanela($novo)) {
                    $erros['data_hora_marcada'] = 'Informe uma data e um horário válidos para a reunião.';
                } else {
                    $m = $novo;
                }
            }
        }
        $real = $r['data_hora_realizada'] === null ? null : (string) $r['data_hora_realizada'];
        if ($realizada !== null && $realizada !== '') {
            if ($r['status'] !== 'realizada') {
                $erros['data_hora_realizada'] = 'Só dá para informar o horário realizado de uma reunião realizada.';
            } else {
                $erro = $this->conferirRealizada($realizada, $m);
                if (is_string($erro)) {
                    $erros['data_hora_realizada'] = $erro;
                } else {
                    $real = $erro[0];
                }
            }
        }
        $ids = $participantes === null ? null : $this->participantesValidos($equipeId, null, $participantes, $erros);
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $agora = Relogio::agora()->format('Y-m-d H:i:s');
        $this->reunioes->atualizar($id, $equipeId, (string) $t, $m, $agora);
        if ($ids !== null) {
            $this->reunioes->definirParticipantes($id, $ids);
        }
        if ($r['status'] === 'realizada' && $real !== null && $real !== $r['data_hora_realizada']) {
            $this->reunioes->corrigirRealizada($id, $equipeId, $real, $agora);
        }
        return Resultado::ok(['item' => $this->item($this->reunioes->buscar($id, $equipeId) ?? [])], 'Reunião atualizada.');
    }

    /** Registra que a reunião foi feita. Sem horário informado, vale agora. */
    public function realizar(int $equipeId, int $id, $quando): Resultado
    {
        $r = $this->reunioes->buscar($id, $equipeId);
        if ($r === null) {
            return Resultado::falha('Reunião não encontrada.');
        }
        if ($r['status'] !== 'agendada') {
            return Resultado::falha($r['status'] === 'realizada' ? 'Esta reunião já foi registrada como realizada.' : 'Uma reunião cancelada não pode ser realizada.');
        }
        $agora = Relogio::agora();
        $erro = $quando === null || $quando === '' ? [$agora->format('Y-m-d H:i:s')] : $this->conferirRealizada($quando, (string) $r['data_hora_marcada']);
        if (is_string($erro)) {
            return Resultado::falha($erro, ['data_hora_realizada' => $erro]);
        }
        // sem horário informado vale agora; se agora for antes do marcado (reunião adiantada), recusa com a mesma mensagem
        if ($erro[0] < (string) $r['data_hora_marcada']) {
            $msg = 'O horário realizado não pode ser antes do horário marcado.';
            return Resultado::falha($msg, ['data_hora_realizada' => $msg]);
        }
        $this->reunioes->realizar($id, $equipeId, $erro[0], $agora->format('Y-m-d H:i:s'));
        return Resultado::ok(['item' => $this->item($this->reunioes->buscar($id, $equipeId) ?? [])], 'Reunião registrada como realizada.');
    }

    public function cancelar(int $equipeId, int $id): Resultado
    {
        $r = $this->reunioes->buscar($id, $equipeId);
        if ($r === null) {
            return Resultado::falha('Reunião não encontrada.');
        }
        if ($r['status'] !== 'agendada') {
            return Resultado::falha($r['status'] === 'cancelada' ? 'Esta reunião já está cancelada.' : 'Uma reunião realizada não pode ser cancelada.');
        }
        $this->reunioes->cancelar($id, $equipeId, Relogio::agora()->format('Y-m-d H:i:s'));
        return Resultado::ok(['item' => $this->item($this->reunioes->buscar($id, $equipeId) ?? [])], 'Reunião cancelada.');
    }

    /** @return array{0: string}|string o horário normalizado (em lista) ou a mensagem de erro */
    private function conferirRealizada($valor, string $marcada)
    {
        $q = RegistroValidador::dataHora($valor);
        if ($q === null) {
            return 'Informe uma data e um horário válidos para o horário realizado.';
        }
        if ($q < $marcada) {
            return 'O horário realizado não pode ser antes do horário marcado.';
        }
        if ($q > Relogio::agora()->format('Y-m-d H:i:s')) {
            return 'O horário realizado não pode estar no futuro.';
        }
        return [$q];
    }

    /** @param array<string, mixed> $r @param array<int, int> $part @return array<string, mixed> */
    private function item(array $r, ?array $part = null): array
    {
        $part ??= isset($r['id']) ? $this->reunioes->participantes((int) $r['id']) : [];
        return [
            'id' => (int) ($r['id'] ?? 0),
            'titulo' => (string) ($r['titulo'] ?? ''),
            'data_hora_marcada' => (string) ($r['data_hora_marcada'] ?? ''),
            'data_hora_realizada' => isset($r['data_hora_realizada']) ? (string) $r['data_hora_realizada'] : null,
            'status' => (string) ($r['status'] ?? ''),
            'criado_em' => (string) ($r['criado_em'] ?? ''),
            'atualizado_em' => isset($r['atualizado_em']) ? (string) $r['atualizado_em'] : null,
            'participantes' => array_values($part),
        ];
    }
}
