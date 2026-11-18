<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\TreinamentoRepository;
use App\Repositories\VendedorRepository;

/**
 * Treinamentos: data e hora, assunto e lista de presença.
 *  - o líder cria e edita os treinamentos DA SUA EQUIPE;
 *  - o treinamento geral (sem equipe, criado pelo admin) aparece para todos os líderes, mas só o admin o altera;
 *  - a presença é marcada pelo líder só para os closers da equipe dele, tanto nos da equipe quanto nos gerais (a presença de gente de outra
 *    equipe, num geral, nunca é tocada nem mostrada).
 */
final class TreinamentoService
{
    private const LIMITE_LISTA = 200;

    public function __construct(private TreinamentoRepository $treinamentos, private VendedorRepository $vendedores)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(int $equipeId): array
    {
        $time = $this->idsDoTime($equipeId);
        $saida = [];
        foreach (array_slice($this->treinamentos->listar($equipeId), 0, self::LIMITE_LISTA) as $r) {
            $saida[] = $this->item($r, $time, $equipeId);
        }
        return $saida;
    }

    /** Treinamentos da equipe e os gerais com data entre as duas datas e horas (para a agenda). @return array<int, array<string, mixed>> */
    public function entre(int $equipeId, string $de, string $ate): array
    {
        $time = $this->idsDoTime($equipeId);
        return array_map(fn(array $r): array => $this->item($r, $time, $equipeId), $this->treinamentos->entre($equipeId, $de, $ate));
    }

    /** Cancela um treinamento da equipe: fica no histórico como cancelado, sem edição nem presença. O geral só o administrador cancela. */
    public function cancelar(int $equipeId, int $id): Resultado
    {
        $r = $this->treinamentos->buscar($id, $equipeId);
        if ($r === null) {
            return Resultado::falha('Treinamento não encontrado.');
        }
        if ($r['equipe_id'] === null) {
            return Resultado::falha('O treinamento geral só pode ser cancelado pelo administrador.');
        }
        if (!$this->treinamentos->cancelar($id, $equipeId, $this->agora())) {
            return Resultado::falha('Este treinamento já está cancelado.');
        }
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Treinamento cancelado.');
    }

    /** @param mixed $dataHora @param mixed $assunto */
    public function criar(int $equipeId, int $liderVendedorId, $dataHora, $assunto): Resultado
    {
        $erros = $this->validar($dataHora, $assunto);
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $id = $this->treinamentos->inserir($equipeId, (string) RegistroValidador::dataHora($dataHora), (string) RegistroValidador::texto($assunto, 120), $liderVendedorId, $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Treinamento registrado.');
    }

    /** @param mixed $dataHora @param mixed $assunto */
    public function editar(int $equipeId, int $id, $dataHora, $assunto): Resultado
    {
        $r = $this->treinamentos->buscar($id, $equipeId);
        if ($r === null) {
            return Resultado::falha('Treinamento não encontrado.');
        }
        if ($r['equipe_id'] === null) {
            return Resultado::falha('O treinamento geral só pode ser alterado pelo administrador.');
        }
        if (isset($r['cancelado_em'])) {
            return Resultado::falha('Um treinamento cancelado não pode ser alterado.');
        }
        $erros = $this->validar($dataHora, $assunto);
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $this->treinamentos->atualizar($id, $equipeId, (string) RegistroValidador::dataHora($dataHora), (string) RegistroValidador::texto($assunto, 120), $this->agora());
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Treinamento atualizado.');
    }

    /**
     * Define quem esteve presente (da equipe do líder). Aceita só ids de closers da equipe: se vier um de fora, a operação inteira é recusada
     * (nada é gravado pela metade).
     * @param mixed $presentes lista de ids de vendedores
     */
    public function definirPresencas(int $equipeId, int $id, $presentes): Resultado
    {
        $t = $this->treinamentos->buscar($id, $equipeId);
        if ($t === null) {
            return Resultado::falha('Treinamento não encontrado.');
        }
        if (isset($t['cancelado_em'])) {
            return Resultado::falha('Um treinamento cancelado não tem presença.');
        }
        if (!is_array($presentes)) {
            return Resultado::falha('Informe a lista de presença.', ['presentes' => 'Informe a lista de presença.']);
        }
        $time = $this->idsDoTime($equipeId);
        $ids = [];
        foreach ($presentes as $v) {
            $n = is_numeric($v) ? (int) $v : 0;
            if (!in_array($n, $time, true)) {
                return Resultado::falha('A lista tem alguém que não é da sua equipe.', ['presentes' => 'A lista tem alguém que não é da sua equipe.']);
            }
            $ids[$n] = $n;
        }
        $this->treinamentos->definirPresencas($id, $time, array_values($ids));
        return Resultado::ok(['item' => $this->itemPorId($id, $equipeId)], 'Presença registrada.');
    }

    /** @param mixed $dataHora @param mixed $assunto @return array<string, string> */
    private function validar($dataHora, $assunto): array
    {
        $erros = [];
        $dh = RegistroValidador::dataHora($dataHora);
        if ($dh === null || !RegistroValidador::naJanela($dh)) {
            $erros['data_hora'] = 'Informe uma data e um horário válidos para o treinamento.';
        }
        $a = RegistroValidador::texto($assunto, 120);
        if ($a === null || $a === '') {
            $erros['assunto'] = $a === null ? 'O assunto pode ter até 120 caracteres.' : 'Informe o assunto do treinamento.';
        }
        return $erros;
    }

    /** @return array<int, int> */
    private function idsDoTime(int $equipeId): array
    {
        return array_map(static fn(array $v): int => $v['id'], $this->vendedores->doTime($equipeId));
    }

    /** @return array<string, mixed> */
    private function itemPorId(int $id, int $equipeId): array
    {
        $r = $this->treinamentos->buscar($id, $equipeId);
        return $r === null ? [] : $this->item($r, $this->idsDoTime($equipeId), $equipeId);
    }

    /**
     * @param array<string, mixed> $r
     * @param array<int, int> $time
     * @return array<string, mixed>
     */
    private function item(array $r, array $time, int $equipeId): array
    {
        $presentes = array_values(array_intersect($this->treinamentos->presentes((int) $r['id']), $time));
        sort($presentes);
        return [
            'id' => (int) $r['id'],
            'data_hora' => (string) $r['data_hora'],
            'assunto' => (string) $r['assunto'],
            'geral' => $r['equipe_id'] === null,
            'cancelado' => isset($r['cancelado_em']),
            'editavel' => $r['equipe_id'] !== null && (int) $r['equipe_id'] === $equipeId && !isset($r['cancelado_em']),
            'presentes' => $presentes,
            'criado_em' => (string) $r['criado_em'],
            'atualizado_em' => isset($r['atualizado_em']) ? (string) $r['atualizado_em'] : null,
        ];
    }

    private function agora(): string
    {
        return Relogio::agora()->format('Y-m-d H:i:s');
    }
}
