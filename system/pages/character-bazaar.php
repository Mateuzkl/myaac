<?php

/**
 * Character Bazaar
 *
 * The game server is the authority for creating and finalizing auctions. This
 * page only accepts bids and uses the same accounts.tibia_coins balance that
 * TFS exposes as Tibia Coins.
 */

defined('MYAAC') or die('Direct access not allowed!');

$title = 'Character Bazaar';
$errors = [];
$success = [];
$coinColumn = 'tibia_coins';
$requiredTables = ['character_auctions', 'character_auction_bids', 'character_auction_history'];
$bazaarAvailable = $db->hasColumn('accounts', $coinColumn);
foreach ($requiredTables as $table) {
	$bazaarAvailable = $bazaarAvailable && $db->hasTable($table);
}

if (!$bazaarAvailable) {
	$errors[] = 'Character Bazaar is not installed yet. Run TFS migration 55 and MyAAC migration 47 against the shared database.';
}

if (isset($_POST['place_bid'])) {
	csrfProtect();
	$auctionId = (int) ($_POST['auction_id'] ?? 0);
	$bidAmount = (int) ($_POST['bid_amount'] ?? 0);

	if (!$bazaarAvailable) {
		// An installation error was already added above.
	} elseif (!$logged) {
		$errors[] = 'You must be logged in to place a bid.';
	} elseif ($auctionId < 1 || $bidAmount < 1) {
		$errors[] = 'Enter a valid auction and bid amount.';
	} else {
		try {
			$db->beginTransaction();
			$auctionStatement = $db->prepare(
				'SELECT `id`, `player_id`, `seller_account_id`, `current_bidder_account_id`, `start_price`, `current_bid`, `status`, `end_at` '
				. 'FROM `character_auctions` WHERE `id` = :id FOR UPDATE'
			);
			$auctionStatement->execute([':id' => $auctionId]);
			$auction = $auctionStatement->fetch(PDO::FETCH_ASSOC);
			$now = time();

			if (!$auction || (int) $auction['status'] !== 1 || (int) $auction['end_at'] <= $now) {
				throw new RuntimeException('This auction is no longer active.');
			}
			if ((int) $auction['seller_account_id'] === (int) $account_logged->getId()) {
				throw new RuntimeException('You cannot bid on your own character.');
			}
			$minimumBid = max((int) $auction['start_price'], (int) $auction['current_bid'] + 1);
			if ($bidAmount < $minimumBid) {
				throw new RuntimeException('Your bid must be at least ' . number_format($minimumBid) . ' transferable Tibia Coins.');
			}

			$balanceStatement = $db->prepare('SELECT `tibia_coins` FROM `accounts` WHERE `id` = :id FOR UPDATE');
			$balanceStatement->execute([':id' => $account_logged->getId()]);
			$balance = $balanceStatement->fetchColumn();
			if ($balance === false || (int) $balance < $bidAmount) {
				throw new RuntimeException('You do not have enough transferable Tibia Coins.');
			}

			$debitStatement = $db->prepare(
				'UPDATE `accounts` SET `tibia_coins` = `tibia_coins` - :amount '
				. 'WHERE `id` = :id AND `tibia_coins` >= :amount'
			);
			$debitStatement->execute([':amount' => $bidAmount, ':id' => $account_logged->getId()]);
			if ($debitStatement->rowCount() !== 1) {
				throw new RuntimeException('Your balance changed. Please try again.');
			}

			if (!empty($auction['current_bidder_account_id']) && (int) $auction['current_bid'] > 0) {
				$refundStatement = $db->prepare(
					'UPDATE `accounts` SET `tibia_coins` = `tibia_coins` + :amount WHERE `id` = :id'
				);
				$refundStatement->execute([
					':amount' => (int) $auction['current_bid'],
					':id' => (int) $auction['current_bidder_account_id'],
				]);
				if ($refundStatement->rowCount() !== 1) {
					throw new RuntimeException('The previous bidder could not be refunded.');
				}
			}

			$updateAuction = $db->prepare(
				'UPDATE `character_auctions` SET `current_bid` = :amount, `current_bidder_account_id` = :bidder '
				. 'WHERE `id` = :id AND `status` = 1 AND `end_at` > :now'
			);
			$updateAuction->execute([
				':amount' => $bidAmount,
				':bidder' => $account_logged->getId(),
				':id' => $auctionId,
				':now' => $now,
			]);
			if ($updateAuction->rowCount() !== 1) {
				throw new RuntimeException('This auction expired while your bid was being placed.');
			}

			$bidStatement = $db->prepare(
				'INSERT INTO `character_auction_bids` (`auction_id`, `bidder_account_id`, `bid_amount`, `created_at`) '
				. 'VALUES (:auction_id, :account_id, :amount, :created_at)'
			);
			$bidStatement->execute([
				':auction_id' => $auctionId,
				':account_id' => $account_logged->getId(),
				':amount' => $bidAmount,
				':created_at' => $now,
			]);
			$historyStatement = $db->prepare(
				'INSERT INTO `character_auction_history` (`auction_id`, `action`, `account_id`, `player_id`, `amount`, `message`, `created_at`) '
				. 'VALUES (:auction_id, :action, :account_id, :player_id, :amount, :message, :created_at)'
			);
			$historyStatement->execute([
				':auction_id' => $auctionId,
				':action' => 'bid',
				':account_id' => $account_logged->getId(),
				':player_id' => (int) $auction['player_id'],
				':amount' => $bidAmount,
				':message' => 'Bid placed through MyAAC.',
				':created_at' => $now,
			]);
			$db->commit();
			$success[] = 'Your bid was placed successfully.';
		} catch (Throwable $exception) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			$errors[] = $exception->getMessage();
		}
	}
}

$activeAuctions = [];
$finishedAuctions = [];
$selectedAuction = null;
$bidHistory = [];
$viewerBalance = null;

if ($bazaarAvailable) {
	$activeAuctions = $db->query(
		'SELECT `id`, `player_name`, `snapshot_level`, `snapshot_vocation`, `start_price`, `current_bid`, '
		. '`current_bidder_account_id`, `commission_percent`, `end_at`, `description` '
		. 'FROM `character_auctions` WHERE `status` = 1 ORDER BY `end_at` ASC'
	)->fetchAll(PDO::FETCH_ASSOC);
	$finishedAuctions = $db->query(
		'SELECT `id`, `player_name`, `snapshot_level`, `snapshot_vocation`, `start_price`, `current_bid`, `final_price`, '
		. '`status`, `finished_at`, `description` FROM `character_auctions` '
		. 'WHERE `status` IN (2, 3) ORDER BY `finished_at` DESC LIMIT 50'
	)->fetchAll(PDO::FETCH_ASSOC);

	$selectedId = (int) ($_GET['auction'] ?? 0);
	if ($selectedId > 0) {
		$selectedStatement = $db->prepare('SELECT * FROM `character_auctions` WHERE `id` = :id LIMIT 1');
		$selectedStatement->execute([':id' => $selectedId]);
		$selectedAuction = $selectedStatement->fetch(PDO::FETCH_ASSOC) ?: null;
		if ($selectedAuction) {
			$historyStatement = $db->prepare(
				'SELECT `bid_amount`, `created_at` FROM `character_auction_bids` WHERE `auction_id` = :id ORDER BY `created_at` DESC'
			);
			$historyStatement->execute([':id' => $selectedId]);
			$bidHistory = $historyStatement->fetchAll(PDO::FETCH_ASSOC);
		}
	}

	if ($logged) {
		$balanceStatement = $db->prepare('SELECT `tibia_coins` FROM `accounts` WHERE `id` = :id');
		$balanceStatement->execute([':id' => $account_logged->getId()]);
		$viewerBalance = (int) $balanceStatement->fetchColumn();
	}
}

$vocations = config('vocations') ?: [];
$prepareAuction = static function (array $auction) use ($vocations): array {
	$auction['vocation_name'] = $vocations[(int) $auction['snapshot_vocation']] ?? 'None';
	$auction['display_bid'] = max((int) $auction['start_price'], (int) $auction['current_bid']);
	$auction['has_bidder'] = !empty($auction['current_bidder_account_id']);
	$auction['ends_at_text'] = !empty($auction['end_at']) ? date('Y-m-d H:i', (int) $auction['end_at']) : '-';
	$auction['finished_at_text'] = !empty($auction['finished_at']) ? date('Y-m-d H:i', (int) $auction['finished_at']) : '-';
	return $auction;
};
$activeAuctions = array_map($prepareAuction, $activeAuctions);
$finishedAuctions = array_map($prepareAuction, $finishedAuctions);
if ($selectedAuction) {
	$selectedAuction = $prepareAuction($selectedAuction);
}

/** @var Twig\Environment $twig */
$twig->display('character-bazaar.html.twig', [
	'errors' => $errors,
	'success' => $success,
	'active_auctions' => $activeAuctions,
	'finished_auctions' => $finishedAuctions,
	'selected_auction' => $selectedAuction,
	'bid_history' => $bidHistory,
	'logged' => $logged,
	'viewer_balance' => $viewerBalance,
	'viewer_account_id' => $logged ? (int) $account_logged->getId() : null,
]);
