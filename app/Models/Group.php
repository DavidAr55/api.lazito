<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUlids;

    public const MINIMUM_PARTICIPANTS = 3;

    /** Upper bound for searching a draw with exclusions, so an impossible set cannot tie up the server. */
    private const DRAW_TIME_LIMIT_SECONDS = 3.0;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'event_date',
        'budget',
        'theme',
        'theme_description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'budget' => 'integer',
            'drawn_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('label');
    }

    /**
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class)->orderBy('id');
    }

    /**
     * @return HasMany<Exclusion, $this>
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(Exclusion::class);
    }

    /**
     * @return HasMany<FixedAssignment, $this>
     */
    public function fixedAssignments(): HasMany
    {
        return $this->hasMany(FixedAssignment::class);
    }

    /**
     * Creates the group with its categories and participants, and draws the assignments.
     *
     * @param  array{name: string, event_date: string, budget: int, theme: string, theme_description?: string|null, categories?: list<string>, participants: list<string>, exclusions?: list<array{a: string, b: string}>, fixed_assignments?: list<array{giver: string, receiver: string}>}  $data
     *
     * @throws DomainException when the rules leave no valid draw
     */
    public static function openWithDraw(array $data, string $adminToken): self
    {
        return DB::transaction(function () use ($data, $adminToken): self {
            $group = new self([
                ...collect($data)->only(['name', 'event_date', 'budget', 'theme', 'theme_description'])->all(),
                'slug' => self::generateSlug($data['name']),
            ]);
            $group->admin_token_hash = hash('sha256', $adminToken);
            $group->save();

            $group->categories()->createMany(
                collect($data['categories'] ?? [])->map(fn (string $label): array => ['label' => $label])->all(),
            );
            $group->participants()->createMany(
                collect($data['participants'])->map(fn (string $name): array => ['name' => $name])->all(),
            );

            $byName = $group->participants()->get()->keyBy('name');
            $group->replaceExclusions(
                collect($data['exclusions'] ?? [])
                    ->map(fn (array $pair): array => [$byName[$pair['a']]->id, $byName[$pair['b']]->id])
                    ->all(),
            );

            $group->replaceFixedAssignments(
                collect($data['fixed_assignments'] ?? [])
                    ->map(fn (array $pair): array => [$byName[$pair['giver']]->id, $byName[$pair['receiver']]->id])
                    ->all(),
            );

            $group->drawAssignments();

            return $group;
        });
    }

    /**
     * Replaces the category list by label: keeps existing ones, adds new ones, removes the rest.
     * Wishlist items of a removed category stay, without category.
     *
     * @param  list<string>  $labels
     */
    public function syncCategories(array $labels): void
    {
        Category::query()->whereBelongsTo($this)->whereNotIn('label', $labels)->delete();

        $existing = Category::query()->whereBelongsTo($this)->pluck('label')->all();
        $this->categories()->createMany(
            collect($labels)->diff($existing)->map(fn (string $label): array => ['label' => $label])->values()->all(),
        );
    }

    /**
     * Replaces the pairs that must not draw each other. Each pair holds two participant ids of this group.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public function replaceExclusions(array $pairs): void
    {
        Exclusion::query()->whereBelongsTo($this)->delete();

        $this->exclusions()->createMany(
            collect($pairs)
                ->map(fn (array $pair): array => Exclusion::pairAttributes($pair[0], $pair[1]))
                ->unique(fn (array $attributes): string => $attributes['participant_a_id'].'|'.$attributes['participant_b_id'])
                ->values()
                ->all(),
        );
    }

    /**
     * Replaces the hand-picked pairs. Each pair is [giver id, receiver id], both from this group.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public function replaceFixedAssignments(array $pairs): void
    {
        FixedAssignment::query()->whereBelongsTo($this)->delete();

        $this->fixedAssignments()->createMany(
            collect($pairs)
                ->map(fn (array $pair): array => ['giver_id' => $pair[0], 'receiver_id' => $pair[1]])
                ->all(),
        );
    }

    /**
     * Changes the exclusions and/or the hand-picked pairs (null keeps the current ones) and draws again,
     * unless somebody has already seen their result. Nothing changes when the rules leave no valid draw.
     *
     * @param  list<array{0: string, 1: string}>|null  $exclusions
     * @param  list<array{0: string, 1: string}>|null  $fixedAssignments
     *
     * @throws DomainException when the rules leave no valid draw
     */
    public function applyRulesAndRedraw(?array $exclusions = null, ?array $fixedAssignments = null): bool
    {
        return DB::transaction(function () use ($exclusions, $fixedAssignments): bool {
            if ($this->participants()->whereNotNull('revealed_at')->lockForUpdate()->exists()) {
                return false;
            }

            if ($exclusions !== null) {
                $this->replaceExclusions($exclusions);
            }

            if ($fixedAssignments !== null) {
                $this->replaceFixedAssignments($fixedAssignments);
            }

            $this->drawAssignments();

            return true;
        });
    }

    /**
     * Draws again, unless somebody has already seen their result.
     */
    public function redrawIfNobodyRevealed(): bool
    {
        return DB::transaction(function (): bool {
            if ($this->participants()->whereNotNull('revealed_at')->lockForUpdate()->exists()) {
                return false;
            }

            $this->drawAssignments();

            return true;
        });
    }

    public function hasAdminToken(?string $token): bool
    {
        return $token !== null && hash_equals($this->admin_token_hash, hash('sha256', $token));
    }

    /**
     * Draws one single cycle (everybody gives to the next, the last to the first), so nobody draws themselves
     * and nobody forms a closed pair. Pairs in `exclusions` are never neighbours in the cycle, and every
     * hand-picked pair (`fixedAssignments`) is respected: the giver gives to that receiver.
     *
     * @throws DomainException when no cycle satisfies the rules
     */
    public function drawAssignments(): void
    {
        $participants = $this->participants()->get()->values()->all();
        $count = count($participants);

        if ($count < self::MINIMUM_PARTICIPANTS) {
            throw new InvalidArgumentException('A draw needs at least '.self::MINIMUM_PARTICIPANTS.' participants.');
        }

        $forbidden = [];
        foreach ($this->exclusions()->get() as $exclusion) {
            $forbidden[$exclusion->participant_a_id.'|'.$exclusion->participant_b_id] = true;
            $forbidden[$exclusion->participant_b_id.'|'.$exclusion->participant_a_id] = true;
        }

        $fixed = $this->fixedAssignments()->pluck('receiver_id', 'giver_id')->all();

        $order = self::findCycle($participants, $forbidden, $fixed)
            ?? throw new DomainException('The rules leave no valid draw.');

        DB::transaction(function () use ($order, $count): void {
            $this->participants()->update(['receiver_id' => null]);

            // Direct updates: the loaded models still hold the previous receiver, so a repeated pairing would look unchanged.
            foreach ($order as $index => $giver) {
                Participant::query()
                    ->whereKey($giver->getKey())
                    ->update(['receiver_id' => $order[($index + 1) % $count]->getKey()]);
            }

            $this->forceFill(['drawn_at' => now()])->save();
        });
    }

    /**
     * A random cycle through every participant that avoids the forbidden "giver|receiver" pairs and keeps the
     * hand-picked ones. Hand-picked pairs glue people into chains (A gives to B, B to C...), and the search only
     * decides the order of those chains. Quick random shuffles first (uniform, and instant when nothing is
     * restricted), then local repair, then a bounded depth-first search. Everything works on integer positions.
     *
     * @param  list<Participant>  $participants
     * @param  array<string, true>  $forbidden
     * @param  array<string, string>  $fixed  giver id => receiver id
     * @return list<Participant>|null
     */
    private static function findCycle(array $participants, array $forbidden, array $fixed): ?array
    {
        $chains = self::buildChains($participants, $fixed);

        if ($chains === null) {
            return null;
        }

        // A hand-picked pair that is also excluded can never be drawn.
        foreach ($chains as $chain) {
            for ($index = 0; $index < count($chain) - 1; $index++) {
                if (isset($forbidden[$chain[$index]->getKey().'|'.$chain[$index + 1]->getKey()])) {
                    return null;
                }
            }
        }

        // The whole group is already one closed hand-picked cycle.
        if (count($chains) === 1 && self::isClosedCycle($chains[0], $fixed)) {
            return self::edgeIsForbidden(end($chains[0]), $chains[0][0], $forbidden) ? null : $chains[0];
        }

        $count = count($chains);
        $blocked = [];
        foreach ($chains as $from => $fromChain) {
            foreach ($chains as $to => $toChain) {
                $blocked[$from][$to] = ($from === $to && $count > 1)
                    || self::edgeIsForbidden(end($fromChain), $toChain[0], $forbidden);
            }
        }

        $order = match (true) {
            $count === 1 => $blocked[0][0] ? null : [0],
            $count === 2 => $blocked[0][1] || $blocked[1][0] ? null : [0, 1],
            default => self::findChainOrder($blocked, $count),
        };

        if ($order === null) {
            return null;
        }

        return array_merge(...array_map(fn (int $position): array => $chains[$position], $order));
    }

    /**
     * Groups the participants into chains following the hand-picked pairs. Null when the pairs cannot be part of
     * one cycle through everybody (they close a loop that leaves somebody out).
     *
     * @param  list<Participant>  $participants
     * @param  array<string, string>  $fixed
     * @return list<list<Participant>>|null
     */
    private static function buildChains(array $participants, array $fixed): ?array
    {
        $byId = [];
        foreach ($participants as $participant) {
            $byId[$participant->getKey()] = $participant;
        }

        $receivers = array_flip($fixed);
        $visited = [];
        $chains = [];

        foreach ($participants as $participant) {
            if (isset($receivers[$participant->getKey()])) {
                continue;
            }

            $chain = [];
            for ($current = $participant->getKey(); $current !== null; $current = $fixed[$current] ?? null) {
                $chain[] = $byId[$current];
                $visited[$current] = true;
            }
            $chains[] = $chain;
        }

        if (count($visited) === count($participants)) {
            return $chains;
        }

        // Everybody left over is inside closed hand-picked loops: only fine when one loop covers the whole group.
        if ($chains !== []) {
            return null;
        }

        $loop = [];
        for ($current = $participants[0]->getKey(); ! isset($visited[$current]); $current = $fixed[$current]) {
            $visited[$current] = true;
            $loop[] = $byId[$current];
        }

        return count($loop) === count($participants) ? [$loop] : null;
    }

    /**
     * @param  list<Participant>  $chain
     * @param  array<string, string>  $fixed
     */
    private static function isClosedCycle(array $chain, array $fixed): bool
    {
        return ($fixed[end($chain)->getKey()] ?? null) === $chain[0]->getKey();
    }

    /**
     * @param  array<string, true>  $forbidden
     */
    private static function edgeIsForbidden(Participant $from, Participant $to, array $forbidden): bool
    {
        return isset($forbidden[$from->getKey().'|'.$to->getKey()]);
    }

    /**
     * Orders three or more chains into one cycle; null when none exists (or none was found in time).
     *
     * @param  list<array<int, bool>>  $blocked
     * @return list<int>|null
     */
    private static function findChainOrder(array $blocked, int $count): ?array
    {
        if (! self::canFormCycle($blocked, $count)) {
            return null;
        }

        $deadline = microtime(true) + self::DRAW_TIME_LIMIT_SECONDS;

        return self::shuffleUntilValid($blocked, $count)
            ?? self::repairCycle($blocked, $count, $deadline - 1.0)
            ?? self::searchCycle($blocked, $count, $deadline);
    }

    /**
     * Quick rejection of draws that cannot work. Every node needs somebody to give to and somebody to receive
     * from, who must differ when there are three or more, and everybody must be reachable both ways.
     *
     * @param  list<array<int, bool>>  $blocked
     */
    private static function canFormCycle(array $blocked, int $count): bool
    {
        $out = [];
        $in = [];
        foreach ($blocked as $from => $row) {
            foreach ($row as $to => $isBlocked) {
                if (! $isBlocked) {
                    $out[$from][] = $to;
                    $in[$to][] = $from;
                }
            }
        }

        for ($node = 0; $node < $count; $node++) {
            $givesTo = $out[$node] ?? [];
            $receivesFrom = $in[$node] ?? [];

            if ($givesTo === [] || $receivesFrom === [] || (count($givesTo) === 1 && $givesTo === $receivesFrom)) {
                return false;
            }
        }

        return self::reachesEveryNode($out, $count) && self::reachesEveryNode($in, $count);
    }

    /**
     * @param  array<int, list<int>>  $edges
     */
    private static function reachesEveryNode(array $edges, int $count): bool
    {
        $seen = [0 => true];
        $pending = [0];

        while ($pending !== []) {
            foreach ($edges[array_pop($pending)] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $pending[] = $next;
                }
            }
        }

        return count($seen) === $count;
    }

    /**
     * @param  list<array<int, bool>>  $blocked
     * @return list<int>|null
     */
    private static function shuffleUntilValid(array $blocked, int $count): ?array
    {
        for ($attempt = 0; $attempt < 300; $attempt++) {
            $order = self::shuffled(range(0, $count - 1));

            if (self::forbiddenEdges($order, $blocked, array_keys($order)) === 0) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Local search: starting from a random order, swaps people to remove forbidden neighbours.
     * Restarting often beats one long search when many pairs are excluded; it keeps trying until its time is up.
     *
     * @param  list<array<int, bool>>  $blocked
     * @return list<int>|null
     */
    private static function repairCycle(array $blocked, int $count, float $deadline): ?array
    {
        while (microtime(true) < $deadline) {
            $order = self::shuffled(range(0, $count - 1));

            for ($step = 0; $step < 4_000; $step++) {
                if ($step % 100 === 0 && microtime(true) > $deadline) {
                    return null;
                }

                $bad = [];
                foreach ($order as $position => $giver) {
                    if ($blocked[$giver][$order[($position + 1) % $count]]) {
                        $bad[] = $position;
                    }
                }

                if ($bad === []) {
                    return $order;
                }

                $position = $bad[random_int(0, count($bad) - 1)];
                $next = ($position + 1) % $count;
                $other = random_int(0, $count - 1);

                if ($other === $position || $other === $next) {
                    continue;
                }

                $touched = array_unique([$position, $next, ($other - 1 + $count) % $count, $other]);
                $before = self::forbiddenEdges($order, $blocked, $touched);
                [$order[$next], $order[$other]] = [$order[$other], $order[$next]];

                // Keep the swap when it does not make things worse; now and then accept a worse one to escape dead ends.
                if (self::forbiddenEdges($order, $blocked, $touched) > $before && random_int(1, 20) !== 1) {
                    [$order[$next], $order[$other]] = [$order[$other], $order[$next]];
                }
            }
        }

        return null;
    }

    /**
     * How many of the edges starting at the given positions are forbidden.
     *
     * @param  list<int>  $order
     * @param  list<array<int, bool>>  $blocked
     * @param  array<int, int>  $positions
     */
    private static function forbiddenEdges(array $order, array $blocked, array $positions): int
    {
        $count = count($order);
        $found = 0;

        foreach ($positions as $position) {
            $found += (int) $blocked[$order[$position]][$order[($position + 1) % $count]];
        }

        return $found;
    }

    /**
     * Depth-first search with a step and time budget, so an impossible case ends instead of running forever.
     *
     * @param  list<array<int, bool>>  $blocked
     * @return list<int>|null
     */
    private static function searchCycle(array $blocked, int $count, float $deadline): ?array
    {
        $order = self::shuffled(range(0, $count - 1));
        $path = [array_shift($order)];
        $budget = 200_000;

        return self::extendCycle($path, $order, $blocked, $budget, $deadline);
    }

    /**
     * @param  list<int>  $path
     * @param  list<int>  $remaining
     * @param  list<array<int, bool>>  $blocked
     * @return list<int>|null
     */
    private static function extendCycle(array $path, array $remaining, array $blocked, int &$budget, float $deadline): ?array
    {
        $last = end($path);

        if ($remaining === []) {
            return $blocked[$last][$path[0]] ? null : $path;
        }

        $candidates = [];
        foreach ($remaining as $position => $candidate) {
            if ($blocked[$last][$candidate]) {
                continue;
            }

            // Fewest onward options first (Warnsdorff): dead ends surface early instead of deep in the search.
            $options = 0;
            foreach ($remaining as $other) {
                if ($other !== $candidate && ! $blocked[$candidate][$other]) {
                    $options++;
                }
            }

            $candidates[] = ['position' => $position, 'candidate' => $candidate, 'options' => $options];
        }

        usort($candidates, fn (array $left, array $right): int => $left['options'] <=> $right['options']);

        foreach ($candidates as ['position' => $position, 'candidate' => $candidate]) {
            if ($budget-- <= 0 || ($budget % 64 === 0 && microtime(true) > $deadline)) {
                return null;
            }

            $rest = $remaining;
            unset($rest[$position]);

            $found = self::extendCycle([...$path, $candidate], array_values($rest), $blocked, $budget, $deadline);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private static function shuffled(array $items): array
    {
        for ($index = count($items) - 1; $index > 0; $index--) {
            $swapWith = random_int(0, $index);
            [$items[$index], $items[$swapWith]] = [$items[$swapWith], $items[$index]];
        }

        return $items;
    }

    private static function generateSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 30, '') ?: 'intercambio';

        do {
            $slug = $base.'-'.Str::lower(Str::random(4));
        } while (self::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
