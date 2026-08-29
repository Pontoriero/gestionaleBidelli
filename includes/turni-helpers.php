<?php
/**
 * Helper per il calcolo del monte ore settimanale (ordinario + straordinario)
 * dei bidelli.
 */

/**
 * Durata in ore di un turno (mattina o pomeriggio) per un plesso, in base
 * agli orari configurati sul plesso. Ritorna null se gli orari di quel
 * turno_giorno non sono impostati (inizio o fine NULL): il chiamante deve
 * bloccare l'operazione, mai assumere una durata di default — un valore
 * inventato falserebbe silenziosamente il monte ore contrattuale.
 */
function durataTurnoOre(array $plesso, string $turnoGiorno): ?float
{
    $inizio = $plesso["orario_{$turnoGiorno}_inizio"] ?? null;
    $fine = $plesso["orario_{$turnoGiorno}_fine"] ?? null;

    if (!$inizio || !$fine) {
        return null;
    }

    $secondi = strtotime($fine) - strtotime($inizio);

    return $secondi > 0 ? $secondi / 3600 : null;
}

/**
 * Ore totali già assegnate a un bidello in una settimana: somma le durate
 * dei turni con stato 'pianificato' o 'sostituito' (un turno 'assente' non
 * viene lavorato, non pesa sul monte ore). I turni su plessi con orari
 * mancanti contribuiscono 0 ore ma fanno scattare $completo = false, per
 * non restituire un totale che sembra esatto senza esserlo.
 *
 * @return array{ore: float, completo: bool}
 */
function oreTotaliAssegnateSettimana(PDO $pdo, int $bidelloId, string $inizioSettimana, string $fineSettimana): array
{
    $stmt = $pdo->prepare(
        "SELECT t.turno_giorno, p.orario_mattina_inizio, p.orario_mattina_fine,
                p.orario_pomeriggio_inizio, p.orario_pomeriggio_fine
         FROM turni t
         JOIN plessi p ON p.id = t.plesso_id
         WHERE t.bidello_id = :bidello_id
           AND t.data BETWEEN :inizio AND :fine
           AND t.stato IN ('pianificato', 'sostituito')"
    );
    $stmt->execute([
        'bidello_id' => $bidelloId,
        'inizio' => $inizioSettimana,
        'fine' => $fineSettimana,
    ]);

    $ore = 0.0;
    $completo = true;

    foreach ($stmt->fetchAll() as $riga) {
        $durata = durataTurnoOre($riga, $riga['turno_giorno']);
        if ($durata === null) {
            $completo = false;
            continue;
        }
        $ore += $durata;
    }

    return ['ore' => $ore, 'completo' => $completo];
}

/**
 * Situazione ore di un bidello in una settimana: quanto ha già lavorato di
 * ordinario/straordinario e quanto gli resta in entrambe le categorie.
 * Le ore assegnate riempiono prima il monte ordinario, poi straboccano
 * nello straordinario fino al suo tetto.
 *
 * @return array{
 *     ore_ordinarie_assegnate: float,
 *     ore_straordinario_assegnate: float,
 *     ore_residue_ordinarie: float,
 *     ore_residue_straordinario: float,
 *     completo: bool
 * }
 */
function situazioneOreSettimana(PDO $pdo, int $bidelloId, int $oreSettimanali, int $oreStraordinarioMax, string $inizioSettimana, string $fineSettimana): array
{
    $totale = oreTotaliAssegnateSettimana($pdo, $bidelloId, $inizioSettimana, $fineSettimana);
    $oreAssegnate = $totale['ore'];

    $oreOrdinarie = min($oreAssegnate, $oreSettimanali);
    $oreStraordinario = max(0.0, $oreAssegnate - $oreSettimanali);

    return [
        'ore_ordinarie_assegnate' => $oreOrdinarie,
        'ore_straordinario_assegnate' => $oreStraordinario,
        'ore_residue_ordinarie' => max(0.0, $oreSettimanali - $oreOrdinarie),
        'ore_residue_straordinario' => max(0.0, $oreStraordinarioMax - $oreStraordinario),
        'completo' => $totale['completo'],
    ];
}

/**
 * True se assegnare $durataNuovoTurno ore in più a un bidello con la
 * situazione ore corrente $situazione supererebbe anche il tetto
 * straordinari (non solo l'ordinario) — condizione di blocco definitivo,
 * senza conferma possibile.
 */
function superaTettoStraordinario(array $situazione, float $durataNuovoTurno): bool
{
    return $durataNuovoTurno > ($situazione['ore_residue_ordinarie'] + $situazione['ore_residue_straordinario']);
}

/**
 * Soglia giornaliera oltre la quale, senza almeno 30 minuti di pausa tra
 * mattina e pomeriggio nello stesso plesso, serve autorizzazione esplicita:
 * 7 ore e 12 minuti, in minuti.
 */
const SOGLIA_GIORNALIERA_MINUTI = 432;

/**
 * Quadro giornaliero di un bidello in un plesso: durata totale del giorno
 * (mattina + pomeriggio se entrambi presenti, altrimenti solo il turno
 * presente), pausa tra i due turni in minuti (null se non applicabile
 * perché è presente un solo turno), e se supera la soglia giornaliera di
 * 7h12m senza almeno 30 minuti di pausa.
 *
 * Ritorna null se manca un orario del plesso necessario per un turno
 * effettivamente presente ($haMattina/$haPomeriggio true ma l'orario
 * corrispondente non è configurato) — stesso principio del calcolo
 * settimanale: mai una durata o una pausa inventata. Il chiamante deve
 * bloccare l'operazione con un messaggio esplicito in quel caso.
 *
 * @return array{durata_totale_giorno: float, pausa_minuti: ?int, supera_soglia_giornaliera: bool}|null
 */
function situazioneGiornalieraPlesso(array $plesso, bool $haMattina, bool $haPomeriggio): ?array
{
    $durataMattina = 0.0;
    $durataPomeriggio = 0.0;

    if ($haMattina) {
        $durataMattina = durataTurnoOre($plesso, 'mattina');
        if ($durataMattina === null) {
            return null;
        }
    }

    if ($haPomeriggio) {
        $durataPomeriggio = durataTurnoOre($plesso, 'pomeriggio');
        if ($durataPomeriggio === null) {
            return null;
        }
    }

    $durataTotale = $durataMattina + $durataPomeriggio;

    $pausaMinuti = null;
    if ($haMattina && $haPomeriggio) {
        $pausaMinuti = (int) round((strtotime($plesso['orario_pomeriggio_inizio']) - strtotime($plesso['orario_mattina_fine'])) / 60);
    }

    $superaSogliaGiornaliera = ($durataTotale * 60 > SOGLIA_GIORNALIERA_MINUTI) && ($pausaMinuti === null || $pausaMinuti < 30);

    return [
        'durata_totale_giorno' => $durataTotale,
        'pausa_minuti' => $pausaMinuti,
        'supera_soglia_giornaliera' => $superaSogliaGiornaliera,
    ];
}
