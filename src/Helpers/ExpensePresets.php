<?php

namespace App\Helpers;

use PDO;
use PDOException;

/**
 * Saved spends: reusable expense names with a category (table expense_presets).
 * Add/Edit Expense offer them as suggestions on the description field and fill
 * in the category when one is picked.
 */
class ExpensePresets
{
    /**
     * The family's presets, alphabetical. Empty when the table does not exist yet
     * (migration not run), so pages keep working.
     *
     * @return array<int, array{id:int, name:string, category:string}>
     */
    public static function forTenant(PDO $pdo, int $tenantId): array
    {
        try {
            $stmt = $pdo->prepare("SELECT id, name, category FROM expense_presets WHERE tenant_id = ? ORDER BY name ASC");
            $stmt->execute([$tenantId]);
            return array_map(function ($r) {
                return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'category' => (string) $r['category']];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            return [];
        }
    }

    /** True when the expense_presets table exists. */
    public static function ready(PDO $pdo): bool
    {
        try {
            $pdo->query("SELECT 1 FROM expense_presets LIMIT 0");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * <datalist> for the description inputs plus the lowercase-name → category map
     * the page script uses to autofill the category.
     *
     * @param array<int, array{name:string, category:string}> $presets
     * @return array{datalist: string, map: array<string, string>}
     */
    public static function forForm(array $presets, string $datalistId = 'expensePresetList'): array
    {
        $html = '<datalist id="' . Html::e($datalistId) . '">';
        $map = [];
        foreach ($presets as $p) {
            $label = Categories::EXPENSE[$p['category']] ?? $p['category'];
            $html .= '<option value="' . Html::e($p['name']) . '" label="' . Html::e($label) . '"></option>';
            $map[mb_strtolower(trim($p['name']))] = $p['category'];
        }
        $html .= '</datalist>';
        return ['datalist' => $html, 'map' => $map];
    }
}
