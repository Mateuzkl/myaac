<?php
defined('MYAAC') or die('Direct access not allowed!');

$title = 'Character Bazaar';
csrfProtect();

const BAZAAR_STATUS_ACTIVE = 1;
const BAZAAR_STATUS_FINISHED = 2;
const BAZAAR_STATUS_CANCELLED = 3;
const BAZAAR_COMMISSION_PERCENT = 10;

// Set to null to automatically use the first world from your MyAAC config.
// Or specify a string if you want to lock it to a specific world name.
const BAZAAR_WORLD_NAME = null;
// Uses config.lua worldType; fallback to 'pvp'
function bazaarWorldType(): string
{
	$type = strtolower((string)(configLua('worldType') ?: 'pvp'));
	$valid = ['pvp', 'no-pvp', 'pvp-enforced'];
	return in_array($type, $valid, true) ? $type : 'pvp';
}

function bazaarWorldName(): string
{
	if (BAZAAR_WORLD_NAME !== null) {
		return BAZAAR_WORLD_NAME;
	}

	$worlds = config('worlds');
	if (!empty($worlds)) {
		return (string)reset($worlds);
	}

	$serverName = configLua('serverName');
	return !empty($serverName) ? (string)$serverName : (configLua('servername') ?: 'Tibia');
}

function bazaarWorldTypes(): array
{
	return [
		'pvp' => 'PVP',
		'optional-pvp' => 'Optional PVP',
		'retro-pvp' => 'Retro PVP',
		'hardcore-pvp' => 'Hardcore PVP',
	];
}

function bazaarVocationGroups(): array
{
	return [
		1 => ['label' => 'Sorcerer', 'ids' => [1, 5]],
		2 => ['label' => 'Druid', 'ids' => [2, 6]],
		3 => ['label' => 'Paladin', 'ids' => [3, 7]],
		4 => ['label' => 'Knight', 'ids' => [4, 8]],
		9 => ['label' => 'Monk', 'ids' => [9, 10]],
	];
}

function bazaarVocationName(int $vocationId): string
{
	foreach (bazaarVocationGroups() as $group) {
		if (in_array($vocationId, $group['ids'], true)) {
			return $group['label'];
		}
	}

	$vocations = config('vocations') ?? [];
	return $vocations[$vocationId] ?? (string)$vocationId;
}

function bazaarVocationFilterIds(string $baseVocation): array
{
	$groups = bazaarVocationGroups();
	$baseId = (int)$baseVocation;
	if (!isset($groups[$baseId])) {
		return [];
	}

	return array_map('intval', $groups[$baseId]['ids']);
}

function bazaarSkillOptions(): array
{
	return [
		'level' => ['label' => 'Level', 'sql' => 'ca.`level`'],
		'magic' => ['label' => 'Magic Level', 'sql' => 'p.`maglevel`'],
		'fist' => ['label' => 'Fist Fighting', 'sql' => 'p.`skill_fist`'],
		'club' => ['label' => 'Club Fighting', 'sql' => 'p.`skill_club`'],
		'sword' => ['label' => 'Sword Fighting', 'sql' => 'p.`skill_sword`'],
		'axe' => ['label' => 'Axe Fighting', 'sql' => 'p.`skill_axe`'],
		'distance' => ['label' => 'Distance Fighting', 'sql' => 'p.`skill_dist`'],
		'shielding' => ['label' => 'Shielding', 'sql' => 'p.`skill_shielding`'],
		'fishing' => ['label' => 'Fishing', 'sql' => 'p.`skill_fishing`'],
	];
}

function bazaarSortOptions(): array
{
	return [
		'ending_soon' => 'Ending Soon',
		'newest' => 'Newest Auctions',
		'highest_visible_bid' => 'Highest Visible Bid',
		'lowest_visible_bid' => 'Lowest Visible Bid',
		'highest_level' => 'Highest Level',
		'lowest_level' => 'Lowest Level',
		'highest_skill' => 'Highest Selected Skill',
		'lowest_skill' => 'Lowest Selected Skill',
		'name_asc' => 'Name A-Z',
		'name_desc' => 'Name Z-A',
	];
}

function bazaarNormalizeTextFilter(string $value, int $maxLength): string
{
	$value = trim($value);
	if (strlen($value) > $maxLength) {
		$value = substr($value, 0, $maxLength);
	}

	return $value;
}

function bazaarLikeValue(string $value): string
{
	return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

function bazaarReadFilters(): array
{
	$skillOptions = bazaarSkillOptions();
	$sortOptions = bazaarSortOptions();
	$worldTypes = bazaarWorldTypes();
	$vocations = bazaarVocationGroups();

	$filters = [
		'search' => bazaarNormalizeTextFilter((string)($_GET['search'] ?? ''), 50),
		'world_name' => bazaarNormalizeTextFilter((string)($_GET['world_name'] ?? ''), 50),
		'world_type' => (string)($_GET['world_type'] ?? ''),
		'vocation' => (string)($_GET['vocation'] ?? ''),
		'min_level' => (string)($_GET['min_level'] ?? ''),
		'max_level' => (string)($_GET['max_level'] ?? ''),
		'skill' => (string)($_GET['skill'] ?? 'level'),
		'min_skill' => (string)($_GET['min_skill'] ?? ''),
		'max_skill' => (string)($_GET['max_skill'] ?? ''),
		'sort' => (string)($_GET['sort'] ?? 'ending_soon'),
	];

	if (!isset($worldTypes[$filters['world_type']])) {
		$filters['world_type'] = '';
	}
	if (!isset($skillOptions[$filters['skill']])) {
		$filters['skill'] = 'level';
	}
	if (!isset($sortOptions[$filters['sort']])) {
		$filters['sort'] = 'ending_soon';
	}
	if ($filters['vocation'] !== '' && !isset($vocations[(int)$filters['vocation']])) {
		$filters['vocation'] = '';
	}

	foreach (['min_level', 'max_level', 'min_skill', 'max_skill'] as $key) {
		if ($filters[$key] !== '') {
			$value = max(0, (int)$filters[$key]);
			$filters[$key] = (string)$value;
		}
	}

	return $filters;
}

function bazaarBuildAuctionQuery($db, array $filters): string
{
	$skillOptions = bazaarSkillOptions();
	$skillSql = $skillOptions[$filters['skill']]['sql'];
	$displayBidSql = 'CASE WHEN ca.`current_bid` > 0 THEN GREATEST(ca.`start_price`, ca.`public_bid`) ELSE ca.`start_price` END';
	$now = time();
	$where = ['ca.`status` = ' . BAZAAR_STATUS_ACTIVE, 'ca.`end_at` > ' . $now];

	if ($filters['search'] !== '') {
		$where[] = 'ca.`player_name` LIKE ' . $db->quote(bazaarLikeValue($filters['search']));
	}
	if ($filters['world_name'] !== '' && strcasecmp($filters['world_name'], bazaarWorldName()) !== 0) {
		$where[] = '0 = 1';
	}
	if ($filters['world_type'] !== '' && $filters['world_type'] !== bazaarWorldType()) {
		$where[] = '0 = 1';
	}
	if ($filters['vocation'] !== '') {
		$vocationIds = bazaarVocationFilterIds($filters['vocation']);
		if (empty($vocationIds)) {
			$where[] = '0 = 1';
		} else {
			$where[] = 'ca.`vocation` IN (' . implode(',', $vocationIds) . ')';
		}
	}
	if ($filters['min_level'] !== '') {
		$where[] = 'ca.`level` >= ' . (int)$filters['min_level'];
	}
	if ($filters['max_level'] !== '') {
		$where[] = 'ca.`level` <= ' . (int)$filters['max_level'];
	}
	if ($filters['min_skill'] !== '') {
		$where[] = $skillSql . ' >= ' . (int)$filters['min_skill'];
	}
	if ($filters['max_skill'] !== '') {
		$where[] = $skillSql . ' <= ' . (int)$filters['max_skill'];
	}

	switch ($filters['sort']) {
		case 'newest':
			$orderBy = 'ca.`created_at` DESC';
			break;
		case 'highest_visible_bid':
			$orderBy = $displayBidSql . ' DESC, ca.`end_at` ASC';
			break;
		case 'lowest_visible_bid':
			$orderBy = $displayBidSql . ' ASC, ca.`end_at` ASC';
			break;
		case 'highest_level':
			$orderBy = 'ca.`level` DESC, ca.`end_at` ASC';
			break;
		case 'lowest_level':
			$orderBy = 'ca.`level` ASC, ca.`end_at` ASC';
			break;
		case 'highest_skill':
			$orderBy = $skillSql . ' DESC, ca.`end_at` ASC';
			break;
		case 'lowest_skill':
			$orderBy = $skillSql . ' ASC, ca.`end_at` ASC';
			break;
		case 'name_asc':
			$orderBy = 'ca.`player_name` ASC';
			break;
		case 'name_desc':
			$orderBy = 'ca.`player_name` DESC';
			break;
		default:
			$orderBy = 'ca.`end_at` ASC, ca.`public_bid` DESC';
			break;
	}

	return 'SELECT ca.*, ca.`end_at` AS `ends_at`, ' .
		'p.`maglevel`, p.`skill_fist`, p.`skill_club`, p.`skill_sword`, p.`skill_axe`, p.`skill_dist`, p.`skill_shielding`, p.`skill_fishing`, ' .
		$skillSql . ' AS `selected_skill_value` ' .
		'FROM `character_auctions` ca ' .
		'INNER JOIN `players` p ON p.`id` = ca.`player_id` ' .
		'WHERE ' . implode(' AND ', $where) . ' ' .
		'ORDER BY ' . $orderBy . ' LIMIT 100';
}

function bazaarHistory($db, int $auctionId, ?int $accountId, string $action, string $message): void
{
	$db->query('INSERT INTO `character_auction_history` (`auction_id`, `action`, `account_id`, `player_id`, `amount`, `message`, `created_at`) VALUES (' .
		$auctionId . ', ' . $db->quote($action) . ', ' . ($accountId === null ? 'NULL' : $accountId) . ', NULL, NULL, ' . $db->quote($message) . ', ' . time() . ')');
}

function bazaarStoreHistory($db, int $accountId, string $description, int $amount): void { /* store_history table not present in this schema */ }

/**
 * Auction finalization is handled by the game server (TFS).
 * This function is intentionally empty to prevent web-side finalization races.
 */
function bazaarFinalizeExpired($db): void
{
	// Intentionally empty: TFS is the authority for finalization
}

function bazaarAccountCoins($db, int $accountId): int
{
	$row = $db->query('SELECT `tibia_coins` FROM `accounts` WHERE `id` = ' . $accountId . ' LIMIT 1')->fetch();
	return $row ? (int)$row['tibia_coins'] : 0;
}

function bazaarOutfitUrl(array $auction): string
{
	return setting('core.outfit_images_url') .
		'?id=' . (int)$auction['looktype'] .
		(!empty($auction['lookaddons']) ? '&addons=' . (int)$auction['lookaddons'] : '') .
		'&head=' . (int)$auction['lookhead'] .
		'&body=' . (int)$auction['lookbody'] .
		'&legs=' . (int)$auction['looklegs'] .
		'&feet=' . (int)$auction['lookfeet'];
}

function bazaarDecorateAuction(array $auction): array
{
	$worldTypes = bazaarWorldTypes();
	$startPrice = (int)$auction['start_price'];
	$currentBid = (int)$auction['current_bid'];
	$publicBid = isset($auction['public_bid']) ? (int)$auction['public_bid'] : 0;
	$auction['display_bid'] = $currentBid > 0 ? max($startPrice, $publicBid) : $startPrice;
	$auction['minimum_bid'] = $currentBid > 0 ? $auction['display_bid'] + 1 : $startPrice;
	$auction['is_active'] = (int)$auction['status'] === BAZAAR_STATUS_ACTIVE && (int)$auction['ends_at'] > time();
	$auction['ends_at_text'] = date('M d Y, H:i:s', (int)$auction['ends_at']);
	$auction['ends_at_short_text'] = date('M d Y, H:i', (int)$auction['ends_at']);
	$auction['outfit_url'] = bazaarOutfitUrl($auction);
	$auction['world_name'] = bazaarWorldName();
	$auction['world_type'] = $worldTypes[bazaarWorldType()] ?? 'PVP';
	$auction['vocation_name'] = bazaarVocationName((int)$auction['vocation']);
	$auction['selected_skill_value'] = isset($auction['selected_skill_value']) ? (int)$auction['selected_skill_value'] : (int)$auction['level'];
	$auction['sale_commission_percent'] = isset($auction['commission_percent']) ? (int)$auction['commission_percent'] : BAZAAR_COMMISSION_PERCENT;
	$auction['sale_commission_amount'] = (int)floor($currentBid * $auction['sale_commission_percent'] / 100);
	$auction['seller_payout_amount'] = max(0, $currentBid - $auction['sale_commission_amount']);
	return $auction;
}

function bazaarDetailTabs(): array
{
	return [
		'general' => 'General',
		'items' => 'Items',
		'cosmetics' => 'Cosmetics',
		'blessings' => 'Blessings',
		'charms' => 'Charms',
		'titles' => 'Titles',
		'bestiary' => 'Bestiary',
		'mastery' => 'Mastery',
		'bosstiary' => 'Bosstiary',
		'gems' => 'Gems',
		'proficiency' => 'Proficiency',
		'battlepass' => 'Battlepass',
		'quests' => 'Quests',
		'achievements' => 'Achievements',
		'bounty' => 'Bounty',
		'hirelings' => 'Hirelings',
	];
}

function bazaarReadActiveDetailTab(): string
{
	$tabs = bazaarDetailTabs();
	$tab = (string)($_GET['tab'] ?? 'general');
	return isset($tabs[$tab]) ? $tab : 'general';
}

function bazaarSafeTableName(string $table): bool
{
	return preg_match('/^[a-zA-Z0-9_]+$/', $table) === 1;
}

function bazaarTableExists($db, string $table): bool
{
	if (!bazaarSafeTableName($table)) {
		return false;
	}

	try {
		$row = $db->query('SHOW TABLES LIKE ' . $db->quote($table))->fetch();
		return (bool)$row;
	} catch (Throwable $e) {
		return false;
	}
}

function bazaarColumnExists($db, string $table, string $column): bool
{
	if (!bazaarSafeTableName($table) || !bazaarSafeTableName($column)) {
		return false;
	}

	try {
		$row = $db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $db->quote($column))->fetch();
		return (bool)$row;
	} catch (Throwable $e) {
		return false;
	}
}

function bazaarFetchRows($db, string $table, int $playerId, string $orderBy = '', int $limit = 200): array
{
	if (!bazaarTableExists($db, $table) || !bazaarColumnExists($db, $table, 'player_id')) {
		return [];
	}

	$sql = 'SELECT * FROM `' . $table . '` WHERE `player_id` = ' . $playerId;
	if ($orderBy !== '') {
		$sql .= ' ORDER BY ' . $orderBy;
	}
	if ($limit > 0) {
		$sql .= ' LIMIT ' . (int)$limit;
	}

	try {
		return $db->query($sql)->fetchAll();
	} catch (Throwable $e) {
		return [];
	}
}

function bazaarFetchOne($db, string $table, int $playerId): ?array
{
	$rows = bazaarFetchRows($db, $table, $playerId, '', 1);
	return $rows[0] ?? null;
}

function bazaarFileServerPath(string $relativePath): string
{
	$base = rtrim((string)config('data_path'), '/\\');
	if ($base !== '') {
		return $base . '/' . ltrim($relativePath, '/\\');
	}

	// Default fallback to relative data folder
	return 'data/' . ltrim($relativePath, '/\\');
}

function bazaarXmlNameMap(string $relativePath, string $tag, string $idAttribute, string $nameAttribute): array
{
	static $cache = [];
	$key = $relativePath . ':' . $tag . ':' . $idAttribute . ':' . $nameAttribute;
	if (isset($cache[$key])) {
		return $cache[$key];
	}

	$file = bazaarFileServerPath($relativePath);
	$cache[$key] = [];
	if (!is_file($file)) {
		return $cache[$key];
	}

	try {
		$xml = simplexml_load_file($file);
		if (!$xml) {
			return $cache[$key];
		}

		foreach ($xml->{$tag} as $node) {
			$id = (int)$node[$idAttribute];
			if ($id > 0) {
				$cache[$key][$id] = (string)$node[$nameAttribute];
			}
		}
	} catch (Throwable $e) {
		$cache[$key] = [];
	}

	return $cache[$key];
}

function bazaarItemNameMap(): array
{
	static $items = null;
	if ($items !== null) {
		return $items;
	}

	$items = [];
	$file = bazaarFileServerPath('items/items.xml');
	if (!is_file($file)) {
		return $items;
	}

	try {
		$xml = simplexml_load_file($file);
		if (!$xml) {
			return $items;
		}

		foreach ($xml->item as $item) {
			$name = (string)$item['name'];
			if ($name === '') {
				continue;
			}

			if (isset($item['fromid']) && isset($item['toid'])) {
				for ($id = (int)$item['fromid']; $id <= (int)$item['toid']; $id++) {
					$items[$id] = $name;
				}
			} else {
				$items[(int)$item['id']] = $name;
			}
		}
	} catch (Throwable $e) {
		$items = [];
	}

	return $items;
}

function bazaarSkillProgress($tries): int
{
	$tries = (int)$tries;
	if ($tries <= 0) {
		return 0;
	}

	return min(99, (int)(($tries % 10000) / 100));
}

function bazaarBitCount(int $value): int
{
	$count = 0;
	while ($value > 0) {
		$count += $value & 1;
		$value >>= 1;
	}

	return $count;
}

function bazaarBlobBytes($value): int
{
	return is_string($value) ? strlen($value) : 0;
}

function bazaarKvPrefixRows($db, int $playerId, string $suffixPrefix, int $limit = 200): array
{
	if (!bazaarTableExists($db, 'kv_store') || !bazaarColumnExists($db, 'kv_store', 'key_name')) {
		return [];
	}

	$prefix = 'player.' . $playerId . '.' . $suffixPrefix;
	$like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
	$sql = 'SELECT `key_name`, `timestamp`, LENGTH(`value`) AS `value_bytes` FROM `kv_store` WHERE `key_name` LIKE ' .
		$db->quote($like) . ' ORDER BY `key_name` ASC LIMIT ' . max(1, min(500, $limit));

	try {
		return $db->query($sql)->fetchAll();
	} catch (Throwable $e) {
		return [];
	}
}

function bazaarKvNames($db, int $playerId, string $suffixPrefix, int $limit = 200): array
{
	$rows = bazaarKvPrefixRows($db, $playerId, $suffixPrefix, $limit);
	$names = [];
	$prefix = 'player.' . $playerId . '.' . $suffixPrefix;
	foreach ($rows as $row) {
		$keyName = (string)$row['key_name'];
		$name = substr($keyName, strlen($prefix));
		$name = trim(str_replace(['-progress', '.amount'], '', $name));
		if ($name !== '') {
			$names[] = [
				'name' => $name,
				'value_bytes' => (int)($row['value_bytes'] ?? 0),
				'timestamp' => (int)($row['timestamp'] ?? 0),
			];
		}
	}

	return $names;
}

function bazaarKvCount($db, int $playerId, string $suffixPrefix): int
{
	if (!bazaarTableExists($db, 'kv_store') || !bazaarColumnExists($db, 'kv_store', 'key_name')) {
		return 0;
	}

	$prefix = 'player.' . $playerId . '.' . $suffixPrefix;
	$like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';
	try {
		$row = $db->query('SELECT COUNT(*) AS `total` FROM `kv_store` WHERE `key_name` LIKE ' . $db->quote($like))->fetch();
		return $row ? (int)$row['total'] : 0;
	} catch (Throwable $e) {
		return 0;
	}
}

function bazaarMakeItemRows($db, int $playerId, string $table, string $sourceLabel, int $limit = 220): array
{
	$itemNames = bazaarItemNameMap();
	$rows = bazaarFetchRows($db, $table, $playerId, '`pid` ASC, `sid` ASC', $limit);
	$items = [];
	foreach ($rows as $row) {
		$itemId = (int)($row['itemtype'] ?? 0);
		if ($itemId <= 0) {
			continue;
		}

		$count = (int)($row['count'] ?? 0);
		$items[] = [
			'id' => $itemId,
			'count' => $count,
			'name' => $itemNames[$itemId] ?? ('Item ' . $itemId),
			'source' => $sourceLabel,
			'image_url' => '/images/items/' . $itemId . '.gif',
			'fallback_url' => '/images/items/empty.gif',
		];
	}

	return $items;
}

function bazaarBuildAuctionDetails($db, array $auction): array
{
	$playerId = (int)$auction['player_id'];
	$player = $db->query('SELECT * FROM `players` WHERE `id` = ' . $playerId . ' LIMIT 1')->fetch();
	if (!$player) {
		$player = [];
	}

	$outfitNames = bazaarXmlNameMap('XML/outfits.xml', 'outfit', 'looktype', 'name');
	$mountNames = bazaarXmlNameMap('XML/mounts.xml', 'mount', 'id', 'name');
	$outfits = bazaarFetchRows($db, 'player_outfits', $playerId, '`outfit_id` ASC', 300);
	foreach ($outfits as $key => $row) {
		$outfitId = (int)$row['outfit_id'];
		$outfits[$key]['name'] = $outfitNames[$outfitId] ?? ('Outfit ' . $outfitId);
	}

	$mounts = bazaarFetchRows($db, 'player_mounts', $playerId, '`mount_id` ASC', 300);
	foreach ($mounts as $key => $row) {
		$mountId = (int)$row['mount_id'];
		$mounts[$key]['name'] = $mountNames[$mountId] ?? ('Mount ' . $mountId);
	}

	$charms = bazaarFetchOne($db, 'player_charms', $playerId) ?? [];
	$bosstiary = bazaarFetchOne($db, 'player_bosstiary', $playerId) ?? [];
	$bounty = bazaarFetchOne($db, 'player_bounty_tasks', $playerId) ?? [];
	$weekly = bazaarFetchOne($db, 'player_weekly_tasks', $playerId) ?? [];
	$hirelings = bazaarFetchRows($db, 'player_hirelings', $playerId, '`id` ASC', 100);
	$titleEntries = bazaarKvNames($db, $playerId, 'titles.unlocked.', 250);
	$achievementEntries = bazaarKvNames($db, $playerId, 'achievements.', 250);
	$badgeEntries = bazaarKvNames($db, $playerId, 'badges.unlocked.', 250);
	$gemEntries = bazaarKvNames($db, $playerId, 'wheel-of-destiny.gems.revealed.', 250);
	$initialGems = bazaarKvCount($db, $playerId, 'wheel-of-destiny.gems.initialGems');
	$wheelExtraPoints = bazaarKvCount($db, $playerId, 'wheel-of-destiny.hunting-task-shop-extra-points');
	$storageCount = bazaarTableExists($db, 'player_storage')
		? (int)$db->query('SELECT COUNT(*) AS `total` FROM `player_storage` WHERE `player_id` = ' . $playerId)->fetch()['total']
		: 0;

	$equipment = bazaarMakeItemRows($db, $playerId, 'player_items', 'Inventory', 260);
	$depotItems = bazaarMakeItemRows($db, $playerId, 'player_depotitems', 'Depot', 260);
	$inboxItems = bazaarMakeItemRows($db, $playerId, 'player_inboxitems', 'Inbox', 180);
	$rewardItems = bazaarMakeItemRows($db, $playerId, 'player_rewards', 'Rewards', 180);
	$stashRows = bazaarFetchRows($db, 'player_stash', $playerId, '`item_id` ASC', 220);
	$itemNames = bazaarItemNameMap();
	$stashItems = [];
	foreach ($stashRows as $row) {
		$itemId = (int)($row['item_id'] ?? 0);
		if ($itemId > 0) {
			$stashItems[] = [
				'id' => $itemId,
				'count' => (int)($row['item_count'] ?? 0),
				'name' => $itemNames[$itemId] ?? ('Item ' . $itemId),
				'source' => 'Stash',
				'image_url' => '/images/items/' . $itemId . '.gif',
				'fallback_url' => '/images/items/empty.gif',
			];
		}
	}

	$blessingNames = [
		1 => 'Spark of the Phoenix',
		2 => 'Fire of the Suns',
		3 => 'Spiritual Shielding',
		4 => 'Embrace of Tibia',
		5 => 'Heart of the Mountain',
		6 => 'Blood of the Mountain',
		7 => 'Twist of Fate',
		8 => 'The Wisdom of Solitude',
	];
	$blessings = [];
	foreach ($blessingNames as $index => $name) {
		$column = 'blessings' . $index;
		$active = isset($player[$column]) ? (int)$player[$column] > 0 : (((int)($player['blessings'] ?? 0) & (1 << ($index - 1))) !== 0);
		$blessings[] = [
			'name' => $name,
			'active' => $active,
		];
	}

	$skills = [
		['label' => 'Axe Fighting', 'value' => (int)($player['skill_axe'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_axe_tries'] ?? 0)],
		['label' => 'Club Fighting', 'value' => (int)($player['skill_club'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_club_tries'] ?? 0)],
		['label' => 'Distance Fighting', 'value' => (int)($player['skill_dist'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_dist_tries'] ?? 0)],
		['label' => 'Fishing', 'value' => (int)($player['skill_fishing'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_fishing_tries'] ?? 0)],
		['label' => 'Fist Fighting', 'value' => (int)($player['skill_fist'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_fist_tries'] ?? 0)],
		['label' => 'Magic Level', 'value' => (int)($player['maglevel'] ?? 0), 'progress' => bazaarSkillProgress($player['manaspent'] ?? 0)],
		['label' => 'Shielding', 'value' => (int)($player['skill_shielding'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_shielding_tries'] ?? 0)],
		['label' => 'Sword Fighting', 'value' => (int)($player['skill_sword'] ?? 0), 'progress' => bazaarSkillProgress($player['skill_sword_tries'] ?? 0)],
	];

	return [
		'player' => $player,
		'general_left' => [
			['label' => 'Hit Points', 'value' => number_format((int)($player['healthmax'] ?? 0))],
			['label' => 'Mana', 'value' => number_format((int)($player['manamax'] ?? 0))],
			['label' => 'Capacity', 'value' => number_format((int)($player['cap'] ?? 0))],
			['label' => 'Blessings', 'value' => array_sum(array_map(function ($row) { return !empty($row['active']) ? 1 : 0; }, $blessings)) . ' / ' . count($blessings)],
			['label' => 'Mounts', 'value' => number_format(count($mounts))],
			['label' => 'Outfits', 'value' => number_format(count($outfits))],
			['label' => 'Storage Entries', 'value' => number_format($storageCount)],
		],
		'general_right' => [
			['label' => 'Experience', 'value' => number_format((int)($player['experience'] ?? 0))],
			['label' => 'Gold Balance', 'value' => number_format((int)($player['balance'] ?? 0))],
			['label' => 'Achievement Points', 'value' => number_format((int)($player['achievement_points'] ?? 0))],
			['label' => 'Charm Points', 'value' => number_format((int)($charms['charm_points'] ?? 0))],
			['label' => 'Task Points', 'value' => number_format((int)($player['task_points'] ?? 0))],
			['label' => 'Prey Wildcards', 'value' => number_format((int)($player['prey_wildcard'] ?? 0))],
			['label' => 'Boss Points', 'value' => number_format((int)($player['boss_points'] ?? 0))],
		],
		'skills' => $skills,
		'items' => [
			'equipment' => $equipment,
			'depot' => $depotItems,
			'inbox' => $inboxItems,
			'rewards' => $rewardItems,
			'stash' => $stashItems,
			'total' => count($equipment) + count($depotItems) + count($inboxItems) + count($rewardItems) + count($stashItems),
		],
		'cosmetics' => [
			'outfits' => $outfits,
			'mounts' => $mounts,
			'auras' => [],
			'login_screens' => [],
		],
		'blessings' => $blessings,
		'charms' => [
			'row' => $charms,
			'unlocked_count' => bazaarBitCount((int)($charms['UnlockedRunesBit'] ?? 0)),
			'used_count' => bazaarBitCount((int)($charms['UsedRunesBit'] ?? 0)),
			'charms_bytes' => bazaarBlobBytes($charms['charms'] ?? null),
			'tracker_bytes' => bazaarBlobBytes($charms['tracker_list'] ?? null),
		],
		'titles' => [
			'entries' => $titleEntries,
			'note' => 'No public titles found for this character.',
		],
		'bestiary' => [
			'storage_entries' => $storageCount,
			'note' => 'Bestiary progress is stored by the game server and will be displayed here when structured rows are available.',
		],
		'mastery' => [
			'bytes' => bazaarBlobBytes($player['animus_mastery'] ?? null),
			'note' => 'Mastery profile data is stored in the character record.',
		],
		'bosstiary' => [
			'row' => $bosstiary,
			'tracker_bytes' => bazaarBlobBytes($bosstiary['tracker'] ?? null),
		],
		'gems' => [
			'entries' => $gemEntries,
			'initial_gems_entries' => $initialGems,
			'wheel_extra_points_entries' => $wheelExtraPoints,
			'note' => 'Wheel of Destiny gem records are stored as server profile data.',
		],
		'proficiency' => [
			'bytes' => bazaarBlobBytes($player['weapon_proficiencies'] ?? null),
			'virtue' => (int)($player['virtue'] ?? 0),
			'harmony' => (int)($player['harmony'] ?? 0),
		],
		'battlepass' => [
			'weekly' => $weekly,
			'badges' => $badgeEntries,
			'note' => 'Battlepass public progress is summarized from weekly task data when available.',
		],
		'quests' => [
			'storage_entries' => $storageCount,
			'note' => 'Quest completion is represented by character storage entries in this server schema.',
		],
		'achievements' => [
			'points' => (int)($player['achievement_points'] ?? 0),
			'entries' => $achievementEntries,
			'note' => 'No public achievement entries found for this character.',
		],
		'bounty' => [
			'row' => $bounty,
			'preferred_bytes' => bazaarBlobBytes($bounty['preferred_lists'] ?? null),
			'current_bytes' => bazaarBlobBytes($bounty['current_creatures_list'] ?? null),
		],
		'hirelings' => $hirelings,
	];
}

bazaarFinalizeExpired($db);

$errors = [];
$messages = [];
$confirmCancelAuction = null;
$cancelledAuction = null;
$accountId = $logged ? (int)$account_logged->getId() : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	$auctionId = isset($_POST['auction_id']) ? (int)$_POST['auction_id'] : 0;

	if (!$logged) {
		$errors[] = 'You need to log into your account first.';
	} elseif ($auctionId <= 0) {
		$errors[] = 'Invalid auction.';
	} elseif ($action === 'bid') {
		$amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
		
		// Validate uint32 range (0 to 4,294,967,295)
		if ($amount < 0 || $amount > 4294967295) {
			$errors[] = 'Invalid bid amount. Must be between 0 and 4,294,967,295.';
		} else {
			$auction = $db->query('SELECT *, `end_at` AS `ends_at` FROM `character_auctions` WHERE `id` = ' . $auctionId . ' LIMIT 1')->fetch();
			if (!$auction || (int)$auction['status'] !== BAZAAR_STATUS_ACTIVE || (int)$auction['ends_at'] <= time()) {
				$errors[] = 'This auction is no longer active.';
			} elseif ((int)$auction['seller_account_id'] === $accountId) {
				$errors[] = 'You cannot bid on your own character.';
			} else {
				$auction = bazaarDecorateAuction($auction);
				$minimumBid = (int)$auction['minimum_bid'];
				if ($amount < $minimumBid) {
					$errors[] = 'Your bid is below the minimum required bid.';
				} elseif (bazaarAccountCoins($db, $accountId) < $amount) {
					$errors[] = 'You do not have enough transferable coins.';
				} else {
				$db->query('START TRANSACTION');
				$lockedAuction = $db->query('SELECT *, `end_at` AS `ends_at` FROM `character_auctions` WHERE `id` = ' . $auctionId . ' FOR UPDATE')->fetch();
				$lockedAccount = $db->query('SELECT `tibia_coins` FROM `accounts` WHERE `id` = ' . $accountId . ' FOR UPDATE')->fetch();
				if ($lockedAuction) {
					$lockedAuction = bazaarDecorateAuction($lockedAuction);
				}
				$lockedMinimumBid = $lockedAuction ? (int)$lockedAuction['minimum_bid'] : 0;
				if (!$lockedAuction || (int)$lockedAuction['status'] !== BAZAAR_STATUS_ACTIVE || (int)$lockedAuction['ends_at'] <= time()) {
					$db->query('ROLLBACK');
					$errors[] = 'This auction is no longer active.';
				} elseif ($amount < $lockedMinimumBid) {
					$db->query('ROLLBACK');
					$errors[] = 'Your bid is below the minimum required bid.';
				} elseif (!$lockedAccount || (int)$lockedAccount['tibia_coins'] < $amount) {
					$db->query('ROLLBACK');
					$errors[] = 'You do not have enough transferable coins.';
				} else {
					$previousBidder = (int)$lockedAuction['current_bidder_account_id'];
					$previousBid = (int)$lockedAuction['current_bid'];
					$publicBid = max((int)$lockedAuction['public_bid'], (int)$lockedAuction['start_price']);

					if ($previousBidder === $accountId) {
						if ($amount <= $previousBid) {
							$db->query('ROLLBACK');
							$errors[] = 'Your new maximum bid must be higher than your current maximum bid.';
						} else {
							$difference = $amount - $previousBid;
							$db->query('UPDATE `accounts` SET `tibia_coins` = `tibia_coins` - ' . $difference . ' WHERE `id` = ' . $accountId);
						$db->query('UPDATE `character_auctions` SET `current_bid` = ' . $amount . ' WHERE `id` = ' . $auctionId);
							$db->query('INSERT INTO `character_auction_bids` (`auction_id`, `bidder_account_id`, `bid_amount`, `created_at`) VALUES (' . $auctionId . ', ' . $accountId . ', ' . $amount . ', ' . time() . ')');
							$db->query('COMMIT');
							bazaarHistory($db, $auctionId, $accountId, 'bid', 'Maximum bid increased.');
							$messages[] = 'Your maximum bid has been updated.';
						}
					} elseif ($previousBid > 0 && $amount <= $previousBid) {
						$newPublicBid = max($publicBid, $amount);
						$db->query('UPDATE `character_auctions` SET `public_bid` = ' . $newPublicBid . ' WHERE `id` = ' . $auctionId);
						$db->query('INSERT INTO `character_auction_bids` (`auction_id`, `bidder_account_id`, `bid_amount`, `created_at`) VALUES (' . $auctionId . ', ' . $accountId . ', ' . $amount . ', ' . time() . ')');
						$db->query('COMMIT');
						bazaarHistory($db, $auctionId, $accountId, 'lower_bid', 'Anonymous bid registered below the hidden winning bid.');
						$messages[] = 'Your bid was registered, but another bidder currently has a higher hidden maximum bid.';
					} else {
						// Debit new bidder
						$db->query('UPDATE `accounts` SET `tibia_coins` = `tibia_coins` - ' . $amount . ' WHERE `id` = ' . $accountId);
						
						// Refund previous bidder with overflow and missing account protection
						if ($previousBidder > 0 && $previousBid > 0) {
							// Lock previous bidder account and check for overflow
							$prevAccount = $db->query('SELECT `tibia_coins` FROM `accounts` WHERE `id` = ' . $previousBidder . ' FOR UPDATE')->fetch();
							if ($prevAccount) {
								$currentCoins = (int)$prevAccount['tibia_coins'];
								$maxCoins = 4294967295;
								if ($currentCoins <= $maxCoins - $previousBid) {
									$result = $db->query('UPDATE `accounts` SET `tibia_coins` = `tibia_coins` + ' . $previousBid . ' WHERE `id` = ' . $previousBidder);
									if (!$result || $db->affectedRows() !== 1) {
										$db->query('ROLLBACK');
										$errors[] = 'Failed to refund previous bidder. Transaction cancelled.';
										goto bid_end;
									}
								} else {
									$db->query('ROLLBACK');
									$errors[] = 'Cannot refund previous bidder: coin balance would overflow. Contact administrator.';
									goto bid_end;
								}
							} else {
								$db->query('ROLLBACK');
								$errors[] = 'Previous bidder account not found. Transaction cancelled.';
								goto bid_end;
							}
						}
						
						$newPublicBid = $previousBid > 0 ? max($publicBid, $previousBid) : max($publicBid, $amount);
						$db->query('UPDATE `character_auctions` SET `current_bid` = ' . $amount . ', `current_bidder_account_id` = ' . $accountId . ', `public_bid` = ' . $newPublicBid . ' WHERE `id` = ' . $auctionId);
						$db->query('INSERT INTO `character_auction_bids` (`auction_id`, `bidder_account_id`, `bid_amount`, `created_at`) VALUES (' . $auctionId . ', ' . $accountId . ', ' . $amount . ', ' . time() . ')');
						$db->query('COMMIT');
						bazaarHistory($db, $auctionId, $accountId, 'bid', 'Anonymous winning bid placed.');
						$messages[] = 'Your bid has been placed.';
					}
					bid_end:
				}
			}
		}
	} elseif ($action === 'cancel') {
		$auction = $db->query('SELECT *, `end_at` AS `ends_at` FROM `character_auctions` WHERE `id` = ' . $auctionId . ' LIMIT 1')->fetch();
		if (!$auction || (int)$auction['status'] !== BAZAAR_STATUS_ACTIVE) {
			$errors[] = 'This auction is no longer active.';
		} elseif ((int)$auction['seller_account_id'] !== $accountId) {
			$errors[] = 'You can only cancel your own auctions.';
		} elseif ((int)$auction['current_bid'] > 0) {
			$errors[] = 'Auctions with bids cannot be cancelled.';
		} elseif (($_POST['confirm_cancel'] ?? '') !== 'yes') {
			$confirmCancelAuction = bazaarDecorateAuction($auction);
		} else {
			$db->query('UPDATE `character_auctions` SET `status` = ' . BAZAAR_STATUS_CANCELLED . ', `finished_at` = ' . time() . ' WHERE `id` = ' . $auctionId);
			bazaarHistory($db, $auctionId, $accountId, 'cancelled', 'Auction cancelled by seller.');
			$cancelledAuction = [
				'id' => $auctionId,
				'player_name' => $auction['player_name'],
			];
		}
	}
}

$auctionId = isset($_GET['auction']) ? (int)$_GET['auction'] : 0;
$auction = null;
$auctionDetails = null;
$detailTabs = bazaarDetailTabs();
$activeDetailTab = bazaarReadActiveDetailTab();
$bids = [];
if ($auctionId > 0) {
	$auction = $db->query('SELECT *, `end_at` AS `ends_at` FROM `character_auctions` WHERE `id` = ' . $auctionId . ' LIMIT 1')->fetch();
	if ($auction) {
		$auction = bazaarDecorateAuction($auction);
		if ((int)$auction['status'] === BAZAAR_STATUS_CANCELLED) {
			$history = $db->query('SELECT `action` FROM `character_auction_history` WHERE `auction_id` = ' . $auctionId . ' AND `action` IN (\'cancelled\', \'expired\') ORDER BY `created_at` DESC LIMIT 1')->fetch();
			$auction['cancel_status_text'] = $history && $history['action'] === 'expired'
				? 'This auction ended without a winning bid.'
				: 'This auction was cancelled by the seller.';
		}
		$auctionDetails = bazaarBuildAuctionDetails($db, $auction);
		$bids = $db->query('SELECT `created_at` FROM `character_auction_bids` WHERE `auction_id` = ' . $auctionId . ' ORDER BY `created_at` DESC LIMIT 20')->fetchAll();
	}
}

if (!$confirmCancelAuction && !$cancelledAuction && $logged && isset($_GET['confirm_cancel']) && $auction) {
	if ((int)$auction['status'] !== BAZAAR_STATUS_ACTIVE || (int)$auction['ends_at'] <= time()) {
		$errors[] = 'This auction is no longer active.';
	} elseif ((int)$auction['seller_account_id'] !== $accountId) {
		$errors[] = 'You can only cancel your own auctions.';
	} elseif ((int)$auction['current_bid'] > 0) {
		$errors[] = 'Auctions with bids cannot be cancelled.';
	} else {
		$confirmCancelAuction = $auction;
	}
}

$filters = bazaarReadFilters();
$skillOptions = bazaarSkillOptions();
$sortOptions = bazaarSortOptions();
$worldTypes = bazaarWorldTypes();
$vocationOptions = array_map(static function (array $group): string {
	return $group['label'];
}, bazaarVocationGroups());
$auctions = $db->query(bazaarBuildAuctionQuery($db, $filters))->fetchAll();
foreach ($auctions as $key => $row) {
	$auctions[$key] = bazaarDecorateAuction($row);
}
$coins = $logged ? bazaarAccountCoins($db, $accountId) : 0;

$twig->display('character-bazaar.html.twig', [
	'auctions' => $auctions,
	'auction' => $auction,
	'auction_details' => $auctionDetails,
	'detail_tabs' => $detailTabs,
	'active_detail_tab' => $activeDetailTab,
	'bids' => $bids,
	'errors' => $errors,
	'messages' => $messages,
	'confirm_cancel_auction' => $confirmCancelAuction,
	'cancelled_auction' => $cancelledAuction,
	'filters' => $filters,
	'skill_options' => $skillOptions,
	'sort_options' => $sortOptions,
	'world_type_options' => $worldTypes,
	'vocation_options' => $vocationOptions,
	'current_world_name' => bazaarWorldName(),
	'current_world_type' => $worldTypes[bazaarWorldType()] ?? 'PVP',
	'logged' => $logged,
	'account_id' => $accountId,
	'coins' => $coins,
	'now' => time(),
]);
