<?php

namespace App\Helpers;

/**
 * Single source of truth for expense and income categories.
 * Stored values are the array keys; labels are for display only.
 */
class Categories
{
    public const EXPENSE = [
        'Grocery'       => 'Grocery & Supermarkets',
        'Medical'       => 'Medical & Healthcare',
        'Food'          => 'Food & Dining',
        'Utilities'     => 'Bills & Utilities',
        'Transport'     => 'Transport & Fuel',
        'Shopping'      => 'Shopping & Apparel',
        'Entertainment' => 'Entertainment',
        'Travel'        => 'Travel',
        'Education'     => 'Education',
        'Other'         => 'Other',
    ];

    /** Budget grouping used by the 50/30/20 view. */
    public const NEEDS = ['Grocery', 'Medical', 'Utilities', 'Transport', 'Education'];
    public const WANTS = ['Food', 'Shopping', 'Entertainment', 'Travel', 'Other'];

    public const INCOME = [
        'Salary'     => '💼 Salary',
        'Incentives' => '🎯 Incentives / Commission',
        'Business'   => '🏢 Business Income',
        'Bonus'      => '🎁 Bonus',
        'Investment' => '📈 Investment Return',
        'Freelance'  => '💻 Freelance',
        'Gift'       => '🎀 Gift',
        'Other'      => '🔹 Other',
    ];

    /** @return string[] */
    public static function expenseKeys(): array
    {
        return array_keys(self::EXPENSE);
    }

    /** @return string[] */
    public static function incomeKeys(): array
    {
        return array_keys(self::INCOME);
    }

    public static function isExpense(string $category): bool
    {
        return isset(self::EXPENSE[$category]);
    }

    public static function isIncome(string $category): bool
    {
        return isset(self::INCOME[$category]);
    }

    /**
     * <option> tags for an expense <select>. A $selected value that is not a known
     * category (legacy data such as "Adjustment") is kept as an extra option so that
     * saving a form never silently changes it.
     */
    public static function expenseOptions(?string $selected = null): string
    {
        return self::options(self::EXPENSE, $selected);
    }

    public static function incomeOptions(?string $selected = null): string
    {
        return self::options(self::INCOME, $selected);
    }

    private static function options(array $map, ?string $selected): string
    {
        if ($selected !== null && $selected !== '' && !isset($map[$selected])) {
            $map[$selected] = $selected;
        }
        $html = '';
        foreach ($map as $value => $label) {
            // (string): PHP turns numeric-string array keys into ints
            $html .= '<option value="' . Html::e($value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>'
                . Html::e($label) . '</option>';
        }
        return $html;
    }
}
