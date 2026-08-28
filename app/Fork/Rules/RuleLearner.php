<?php

/*
 * RuleLearner.php
 * Copyright (c) 2026 the fork authors.
 *
 * This file is part of a fork of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

declare(strict_types=1);

namespace FireflyIII\Fork\Rules;

use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FORK: keeps one rule per payee in the learned rule group:
 *   withdrawal  → trigger destination_account_is <expense payee>
 *   deposit     → trigger source_account_is <revenue payee>
 *   action      set_category <category>, fired at store-journal
 * A correction updates the rule's category; clearing the category deactivates the rule.
 */
final class RuleLearner
{
    public static function enabled(): bool
    {
        return (bool) config('fork.learned_rules');
    }

    public static function groupTitle(): string
    {
        return (string) config('fork.learned_rules_group', 'Learned (fork)');
    }

    public function group(User $user): RuleGroup
    {
        $group = RuleGroup::query()->where('user_id', $user->id)->where('title', self::groupTitle())->first();
        if (null !== $group) {
            return $group;
        }

        return RuleGroup::query()->create([
            'user_id'         => $user->id,
            'user_group_id'   => $user->user_group_id,
            'title'           => self::groupTitle(),
            'description'     => 'Rules learned from category corrections (fork). Edit freely; a new correction for the same payee updates its rule.',
            'order'           => (int) RuleGroup::query()->where('user_id', $user->id)->max('order') + 1,
            'active'          => true,
            'stop_processing' => false
        ]);
    }

    /**
     * @return null|Rule the rule that was created or updated
     */
    public function learn(TransactionJournal $journal, null|string $category): null|Rule
    {
        if (!self::enabled()) {
            return null;
        }
        $type = $journal->transactionType->type;
        $legs = [
            TransactionTypeEnum::WITHDRAWAL->value => ['destination_account_is', '>'],
            TransactionTypeEnum::DEPOSIT->value    => ['source_account_is', '<']
        ];
        if (!array_key_exists($type, $legs)) {
            return null; // transfers and the rest carry no payee to learn from
        }
        [$trigger, $direction] = $legs[$type];
        $payee = $journal->transactions()->where('amount', $direction, 0)->first()?->account?->name;
        if (null === $payee || '' === trim((string) $payee)) {
            return null;
        }
        $payee = (string) $payee;

        /** @var User $user */
        $user = $journal->user;

        return DB::transaction(function () use ($user, $trigger, $payee, $category): Rule {
            $group = $this->group($user);
            $rule  = $this->find($group, $trigger, $payee);
            if (null === $category || '' === trim($category)) {
                if (null !== $rule) {
                    $rule->active = false;
                    $rule->save();
                    Log::info(sprintf('FORK learned rule #%d deactivated (category cleared for "%s").', $rule->id, $payee));
                }

                return $rule ?? $this->create($group, $trigger, $payee, null);
            }
            if (null === $rule) {
                return $this->create($group, $trigger, $payee, $category);
            }
            $rule->active = true;
            $rule->save();
            RuleAction::query()->where('rule_id', $rule->id)->where('action_type', 'set_category')->update(['action_value' => $category, 'active' => true]);
            Log::info(sprintf('FORK learned rule #%d updated: "%s" → "%s".', $rule->id, $payee, $category));

            return $rule->fresh(['ruleTriggers', 'ruleActions']);
        });
    }

    private function create(RuleGroup $group, string $trigger, string $payee, null|string $category): Rule
    {
        $rule = Rule::query()->create([
            'rule_group_id' => $group->id,
            'user_id'       => $group->user_id,
            'user_group_id' => $group->user_group_id,
            'title'         => sprintf('Learned: %s', mb_substr($payee, 0, 240)),
            'description'   => 'Created from a category correction.',
            'order'         => (int) Rule::query()->where('rule_group_id', $group->id)->max('order') + 1,
            'active'        => null !== $category,
            'strict'        => true
        ]);
        RuleTrigger::query()->create([
            'rule_id'         => $rule->id,
            'trigger_type'    => 'user_action',
            'trigger_value'   => 'store-journal',
            'order'           => 1,
            'active'          => true,
            'stop_processing' => false
        ]);
        RuleTrigger::query()->create([
            'rule_id'         => $rule->id,
            'trigger_type'    => $trigger,
            'trigger_value'   => $payee,
            'order'           => 2,
            'active'          => true,
            'stop_processing' => false
        ]);
        RuleAction::query()->create([
            'rule_id'         => $rule->id,
            'action_type'     => 'set_category',
            'action_value'    => (string) $category,
            'order'           => 1,
            'active'          => true,
            'stop_processing' => false
        ]);
        Log::info(sprintf('FORK learned rule #%d created: "%s" → "%s".', $rule->id, $payee, (string) $category));

        return $rule->fresh(['ruleTriggers', 'ruleActions']);
    }

    private function find(RuleGroup $group, string $trigger, string $payee): null|Rule
    {
        return Rule::query()
            ->where('rule_group_id', $group->id)
            ->whereHas('ruleTriggers', static fn($q) => $q->where('trigger_type', $trigger)->where('trigger_value', $payee))
            ->orderBy('id')
            ->first();
    }
}
