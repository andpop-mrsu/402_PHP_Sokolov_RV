<?php

declare(strict_types=1);

namespace NevallvonGoodem\GCD\Controller;

use DateTimeImmutable;

use function NevallvonGoodem\GCD\Db\fetchHistory;
use function NevallvonGoodem\GCD\Db\insertGame;
use function NevallvonGoodem\GCD\Db\insertRound;
use function NevallvonGoodem\GCD\Db\openDb;
use function NevallvonGoodem\GCD\Db\upsertPlayer;
use function NevallvonGoodem\GCD\Model\makeRound;
use function NevallvonGoodem\GCD\View\askAnswer;
use function NevallvonGoodem\GCD\View\askPlayerName;
use function NevallvonGoodem\GCD\View\greet;
use function NevallvonGoodem\GCD\View\readMenuChoice;
use function NevallvonGoodem\GCD\View\showCorrect;
use function NevallvonGoodem\GCD\View\showDbUnavailable;
use function NevallvonGoodem\GCD\View\showGameIntro;
use function NevallvonGoodem\GCD\View\showGameResult;
use function NevallvonGoodem\GCD\View\showHistory;
use function NevallvonGoodem\GCD\View\showMainMenu;
use function NevallvonGoodem\GCD\View\showQuestion;
use function NevallvonGoodem\GCD\View\showWrong;

function startGame(): void
{
    $pdo = openDb();

    while (true) {
        showMainMenu();
        $choice = (int) trim(readMenuChoice());

        if ($choice === 1) {
            play($pdo);
            continue;
        }

        if ($choice === 2) {
            if ($pdo === null) {
                showDbUnavailable();
                continue;
            }

            $rows = fetchHistory($pdo, 50);
            showHistory($rows);
            continue;
        }

        if ($choice === 3) {
            return;
        }
        // любой другой ввод — просто показываем меню ещё раз
    }
}

function play(?\PDO $pdo): void
{
    showGameIntro();
    $name = trim(askPlayerName());
    if ($name === '') {
        $name = 'Player';
    }
    greet($name);

    $playedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $roundsToSave = [];
    $isWin = true;

    $roundsCount = 3;

    for ($i = 1; $i <= $roundsCount; $i++) {
        $round = makeRound(1, 100);

        $a = (int) $round['a'];
        $b = (int) $round['b'];
        $correct = (int) $round['correct'];

        showQuestion($a, $b);

        $answerStr = trim(askAnswer());
        $answer = filter_var($answerStr, FILTER_VALIDATE_INT);
        $answerInt = is_int($answer) ? $answer : 0;

        $isCorrect = is_int($answer) && ($answerInt === $correct);

        $roundsToSave[] = [
            'a' => $a,
            'b' => $b,
            'correct' => $correct,
            'answer' => $answerInt,
            'is_correct' => $isCorrect,
        ];

        if ($isCorrect) {
            showCorrect();
            continue;
        }

        showWrong($correct);
        $isWin = false;
        break;
    }

    showGameResult($name, $isWin);

    if ($pdo !== null) {
        $pdo->beginTransaction();
        try {
            $playerId = upsertPlayer($pdo, $name);
            $gameId = insertGame($pdo, $playerId, $playedAt, $isWin);

            foreach ($roundsToSave as $r) {
                insertRound(
                    $pdo,
                    $gameId,
                    (int) $r['a'],
                    (int) $r['b'],
                    (int) $r['correct'],
                    (int) $r['answer'],
                    (bool) $r['is_correct']
                );
            }

            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();
            // Если БД упала — игру не ломаем.
        }
    }
}
