<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/conn/db.php';
require_once __DIR__ . '/../php/functions/transactions.php';
require_once __DIR__ . '/../php/util/inventory_helper.php';

$inventoryType = inventoryType();
?>

<main class="location transactions-page" data-mobile-fixed-page>

  <div class="transactions-fixed-header" data-mobile-fixed-toolbar>
  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="m-0">Transactions</h4>
      <div class="text-muted small">Last 1000 records (filtered view)</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="./Location.php">Back to Locations</a>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Results</div>
          <div class="fs-4 fw-bold"><?= number_format((int)$totalRows) ?></div>
          <div class="text-muted small">
            Net ΔQty:
            <span class="fw-semibold"><?= ($qtyNet >= 0 ? '+' : '') . number_format((int)$qtyNet) ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-8">
      <div class="card shadow-sm summary">
        <div class="card-body">
          <div class="text-muted small mb-2">Breakdown (in this view)</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (['create','add','move','update','adjust','delete','import','other'] as $k): ?>
              <span class="badge <?= badge_class($k) ?>">
                <?= strtoupper($k) ?>: <?= number_format((int)($counts[$k] ?? 0)) ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="card shadow-sm mb-3 transactions-mobile-search">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="GET" action="./transactions.php" id="txFilterForm">

        <!-- Search row (always visible) -->
        <div class="col-12 col-lg-8">
          <label class="form-label mb-1">Search</label>
          <input
            class="form-control"
            name="q"
            value="<?= h($filters['q'] ?? '') ?>"
            placeholder='Search'
          >
        </div>
          <?php
$hasAdv =
  !empty($filters['action']) ||
  !empty($filters['sku']) ||
  !empty($filters['loc']) ||
  !empty($filters['batch']) ||
  !empty($filters['actor']) ||
  !empty($filters['from']) ||
  !empty($filters['to']);
?>

<div class="col-12 col-lg-4 d-flex gap-2 justify-content-lg-end">
 <button
  type="button"
  class="btn btn-outline-primary"
  id="btnAdvToggle"
  data-has-adv="<?= $hasAdv ? '1' : '0' ?>"
  aria-controls="advFilters"
  aria-expanded="false"
>
  <i class="fa-solid fa-filter"></i>
</button>


  <button class="btn btn-primary" type="submit">Apply</button>
  <a class="btn btn-outline-secondary" href="./transactions.php">Clear</a>
</div>


        <!-- Advanced filters (hidden by default) -->
        <div class="col-12" id="advFilters" style="display:none;">
          <div class="row g-2 mt-1">

            <div class="col-6 col-lg-2">
              <label class="form-label mb-1">Action</label>
              <select class="form-select" name="action">
                <option value="">All</option>
                <?php foreach (['create','add','move','update','adjust','delete','import'] as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= (($filters['action'] ?? '') === $opt ? 'selected' : '') ?>>
                    <?= strtoupper($opt) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-6 col-lg-2">
              <label class="form-label mb-1">SKU</label>
              <input class="form-control" name="sku" value="<?= h($filters['sku'] ?? '') ?>" placeholder="e.g. FBN15">
            </div>

            <div class="col-6 col-lg-2">
              <label class="form-label mb-1">Location</label>
              <input class="form-control" name="loc" value="<?= h($filters['loc'] ?? '') ?>" placeholder="e.g. A1">
            </div>

            <div class="col-6 col-lg-2">
              <label class="form-label mb-1">Batch</label>
              <input class="form-control" name="batch" value="<?= h($filters['batch'] ?? '') ?>" placeholder="e.g. BN123">
            </div>

            <div class="col-6 col-lg-2">
              <label class="form-label mb-1">Actor</label>
              <input class="form-control" name="actor" value="<?= h($filters['actor'] ?? '') ?>" placeholder="e.g. Aries">
            </div>

            <div class="col-6 col-lg-1">
              <label class="form-label mb-1">From</label>
              <input class="form-control" type="date" name="from" value="<?= h($filters['from'] ?? '') ?>">
            </div>

            <div class="col-6 col-lg-1">
              <label class="form-label mb-1">To</label>
              <input class="form-control" type="date" name="to" value="<?= h($filters['to'] ?? '') ?>">
            </div>

            <div class="col-12">
              <div class="text-muted small mt-1">
                Tip: You can also type tokens in Search: <code>sku:</code> <code>loc:</code> <code>action:</code> <code>batch:</code> <code>actor:</code> <code>from:</code> <code>to:</code>
              </div>
            </div>

          </div>
        </div>

      </form>
    </div>
  </div>
  </div>

  <!-- Table -->
  <div class="card shadow-sm table transactions-data" data-mobile-scroll-data>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 160px;">Time</th>
            <th style="width: 110px;">Action</th>
            <th>SKU</th>
            <th>Location</th>
            <th class="text-end" style="width: 90px;">ΔQty</th>
            <th style="width: 160px;">Qty</th>
            <th style="width: 140px;">Actor</th>
            <th style="width: 220px;">Details</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">No transactions found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $act = (string)($r['Action'] ?? 'other');
              $delta = (int)($r['DeltaQty'] ?? 0);
            ?>
            <tr>
  <td data-label="Time" class="small text-muted">
    <?= h($r['CreatedAt'] ?? '') ?>
  </td>

  <td data-label="Action">
    <span class="badge <?= badge_class($act) ?>">
      <?= h(strtoupper($act)) ?>
    </span>
  </td>

  <td data-label="SKU" class="fw-semibold">
    <?= h($r['SKU_Code'] ?? '') ?>
  </td>

  <td data-label="Location">
    <?= loc_flow($r) ?>
  </td>

  <td data-label="ΔQty" class="text-end fw-semibold">
    <?= ($delta >= 0 ? '+' : '') . number_format($delta) ?>
  </td>

  <td data-label="Qty" class="small">
    <?= h(qty_flow($r)) ?>
  </td>

  <td data-label="Actor" class="small">
    <?= h($r['Actor'] ?? '—') ?>
  </td>

  <td data-label="Details">
    <details class="small">
      <summary class="text-primary" style="cursor:pointer;">View</summary>
      <div class="mt-2">
        <div><span class="text-muted">Batch:</span> <?= h($r['BatchNo'] ?? '—') ?></div>
        <div><span class="text-muted">Expiry:</span> <?= h($r['ExpiryDate'] ?? '—') ?></div>
        <div><span class="text-muted">Unit:</span> <?= h($r['UnitType'] ?? '—') ?></div>
        <div><span class="text-muted">Qty/ctn:</span> <?= number_format((int)($r['QtyPerCtn'] ?? 0)) ?></div>

        <?php if (!empty($r['Comments'])): ?>
          <div class="mt-1">
            <span class="text-muted">Notes:</span> <?= h($r['Comments']) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($r['EntryID'])): ?>
          <div class="mt-1 text-muted">
            EntryID: <?= (int)$r['EntryID'] ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
  </td>
</tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</main>
