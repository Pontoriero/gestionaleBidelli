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
 * mattina e pomeriggio, serve autorizzazione esplicita: 7 ore e 12 minuti,
 * in minuti. Si applica indipendentemente da quale plesso ospita ciascun
 * turno — un bidello può lavorare mattina in un plesso e pomeriggio in un
 * altro, ma le ore/pausa restano calcolate sulla sua intera giornata.
 */
const SOGLIA_GIORNALIERA_MINUTI = 432;

/**
 * Quadro giornaliero di un bidello: durata totale del giorno (mattina nel
 * suo plesso + pomeriggio nel suo, anche se diversi, o solo il turno
 * presente), pausa fra i due in minuti (null se non applicabile perché è
 * presente un solo turno), e se supera la soglia giornaliera di 7h12m
 * senza almeno 30 minuti di pausa.
 *
 * $plessoMattina/$plessoPomeriggio: il plesso (con i suoi orari) di
 * ciascun turno, o null se quel turno non è presente quel giorno. Quando
 * entrambi sono presenti la pausa si calcola fra l'orario di fine mattina
 * DEL PLESSO DELLA MATTINA e l'orario di inizio pomeriggio DEL PLESSO DEL
 * POMERIGGIO — possono avere orari indipendenti.
 *
 * Ritorna null se manca un orario necessario per un turno effettivamente
 * presente — mai una durata o una pausa inventata. Il chiamante deve
 * bloccare l'operazione con un messaggio esplicito in quel caso.
 *
 * @return array{durata_totale_giorno: float, pausa_minuti: ?int, supera_soglia_giornaliera: bool}|null
 */
function situazioneGiornaliera(?array $plessoMattina, ?array $plessoPomeriggio): ?array
{
    $durataMattina = 0.0;
    $durataPomeriggio = 0.0;

    if ($plessoMattina !== null) {
        $durataMattina = durataTurnoOre($plessoMattina, 'mattina');
        if ($durataMattina === null) {
            return null;
        }
    }

    if ($plessoPomeriggio !== null) {
        $durataPomeriggio = durataTurnoOre($plessoPomeriggio, 'pomeriggio');
        if ($durataPomeriggio === null) {
            return null;
        }
    }

    $durataTotale = $durataMattina + $durataPomeriggio;

    $pausaMinuti = null;
    if ($plessoMattina !== null && $plessoPomeriggio !== null) {
        $pausaMinuti = (int) round((strtotime($plessoPomeriggio['orario_pomeriggio_inizio']) - strtotime($plessoMattina['orario_mattina_fine'])) / 60);
    }

    $superaSogliaGiornaliera = ($durataTotale * 60 > SOGLIA_GIORNALIERA_MINUTI) && ($pausaMinuti === null || $pausaMinuti < 30);

    return [
        'durata_totale_giorno' => $durataTotale,
        'pausa_minuti' => $pausaMinuti,
        'supera_soglia_giornaliera' => $superaSogliaGiornaliera,
    ];
}

/**
 * Stato aggregato (3 livelli) di copertura di un plesso in un giorno, dati
 * i minimi del plesso e il conteggio di assegnati per turno_giorno
 * (es. ['mattina' => 2, 'pomeriggio' => 1]). Usato dalla vista Mese, dove
 * un badge per mattina+pomeriggio separati occuperebbe troppo spazio su
 * ~22 colonne — qui basta sapere se il giorno è a posto, parziale, o no.
 */
function statoGiorno(array $plesso, array $conteggiGiorno): array
{
    $copertoMattina = ($conteggiGiorno['mattina'] ?? 0) >= (int) $plesso['min_bidelli_mattina'];
    $copertoPomeriggio = ($conteggiGiorno['pomeriggio'] ?? 0) >= (int) $plesso['min_bidelli_pomeriggio'];

    if ($copertoMattina && $copertoPomeriggio) {
        return ['classe' => 'badge--ok', 'testo' => 'Coperto'];
    }
    if (!$copertoMattina && !$copertoPomeriggio) {
        return ['classe' => 'badge--danger', 'testo' => 'Scoperto'];
    }
    return ['classe' => 'badge--warn', 'testo' => 'Parziale'];
}

/**
 * Badge ok/danger con conteggio per un singolo turno (mattina o
 * pomeriggio), es. "Coperto 2/3" o "Sotto soglia 1/2". Usato dalle viste
 * Giorno e Settimana (dashboard e turni.php), dove mattina e pomeriggio
 * restano distinti invece di aggregarsi in un unico stato come in Mese.
 */
function badgeTurno(int $assegnati, int $minimo): array
{
    $ok = $assegnati >= $minimo;
    return [
        'classe' => $ok ? 'badge--ok' : 'badge--danger',
        'testo' => ($ok ? 'Coperto' : 'Sotto soglia') . " {$assegnati}/{$minimo}",
    ];
}

/**
 * Filtra una querystring "ritorno" (usata per riportare l'utente alla
 * vista/data corretta dopo un'azione) a un set di caratteri sicuro,
 * altrimenti usa $default. Protezione minima: il valore arriva da
 * GET/POST quindi è manomettibile, anche se qui il rischio pratico è
 * basso (finisce solo dopo "Location: turni.php?..." o "index.php?...").
 */
function ritornoSicuro(string $ritorno, string $default): string
{
    return ($ritorno !== '' && preg_match('/^[a-zA-Z0-9=&%._-]*$/', $ritorno)) ? $ritorno : $default;
}
