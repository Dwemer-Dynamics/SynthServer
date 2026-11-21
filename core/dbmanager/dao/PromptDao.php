<?php

// Reads and edits core_prompt: the stored texts, the custom override and the seeded defaults. Parameterized statements on the shared
// session, no lock and no flush.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractPrompt;

/** Finds rows of core_prompt. */
final class PromptDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $promptKey): ?AbstractPrompt
    {
        return $this->fresh()->find(AbstractPrompt::class, $promptKey);
    }

    /** The custom and default text of one prompt as ['custom_prompt' => ?string, 'default_prompt' => ?string], or null when it has no row. @return array<string, mixed>|null */
    public function texts(string $promptKey): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT custom_prompt, default_prompt FROM public.core_prompt WHERE prompt_key = ? LIMIT 1', [$promptKey]);

        return $row === false ? null : $row;
    }

    /** The custom text when set, else the default (SQL COALESCE: an empty custom text still wins), or null when the prompt has no row. */
    public function effective(string $promptKey): ?string
    {
        $value = $this->connection()->fetchOne('SELECT COALESCE(custom_prompt, default_prompt) FROM public.core_prompt WHERE prompt_key = ? LIMIT 1', [$promptKey]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** The texts of several prompts, keyed by prompt key (rows that exist only). @param list<string> $promptKeys @return array<string, array<string, mixed>> */
    public function textsForKeys(array $promptKeys): array
    {
        $found = [];
        foreach ($promptKeys as $promptKey) {
            $row = $this->texts($promptKey);
            if ($row !== null) {
                $found[$promptKey] = $row;
            }
        }

        return $found;
    }

    /** Every column of the named prompts' rows, in the order of $promptKeys (keys without a row are left out). @param list<string> $promptKeys @return list<array<string, mixed>> */
    public function rowsForKeys(array $promptKeys): array
    {
        if ($promptKeys === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT prompt_key, default_prompt, custom_prompt, description FROM public.core_prompt WHERE prompt_key IN ('
            .implode(', ', array_fill(0, count($promptKeys), '?')).')',
            array_values($promptKeys)
        );
        $order = array_flip(array_values($promptKeys));
        usort($rows, static fn(array $a, array $b): int => ($order[$a['prompt_key']] ?? 999) <=> ($order[$b['prompt_key']] ?? 999));

        return $rows;
    }

    /** Sets (or, for null, clears) the custom text of an existing prompt and stamps updated_at; true when the statement ran. */
    public function setCustom(string $promptKey, ?string $customPrompt): bool
    {
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_prompt SET custom_prompt = ?, updated_at = ? WHERE prompt_key = ?',
                [$customPrompt, date('Y-m-d H:i:s'), $promptKey]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Stores a seeded default text and description, replacing the previous ones; the custom text is kept. True when the statement ran. */
    public function upsertDefault(string $promptKey, string $defaultPrompt, string $description): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_prompt (prompt_key, default_prompt, description) VALUES (?, ?, ?) '
                .'ON CONFLICT (prompt_key) DO UPDATE SET default_prompt = EXCLUDED.default_prompt, description = EXCLUDED.description, updated_at = CURRENT_TIMESTAMP',
                [$promptKey, $defaultPrompt, $description]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractPrompt> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractPrompt::class)->findBy([], ['promptKey' => 'ASC'], max(1, $limit)));
    }
}
