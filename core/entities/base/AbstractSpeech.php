<?php

// The mapped row of public.core_speech. Read-only mapping: reads go through SpeechDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_speech', schema: 'public')]
class AbstractSpeech
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $audios = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $companions = null;

    #[ORM\Column(name: 'emotion_intensity', type: 'text', nullable: true)]
    protected ?string $emotionIntensity = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $emotion = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $gamets;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $listener = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $localts;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $location = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $mood = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'string', nullable: true, length: 1024)]
    protected ?string $sess = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $speaker = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $speech = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $topic = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $ts = null;

    #[ORM\Column(name: 'utterance_id', type: 'text', nullable: true)]
    protected ?string $utteranceId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'audios' => $this->audios,
            'companions' => $this->companions,
            'emotion_intensity' => $this->emotionIntensity,
            'emotion' => $this->emotion,
            'gamets' => $this->gamets,
            'listener' => $this->listener,
            'localts' => $this->localts,
            'location' => $this->location,
            'mood' => $this->mood,
            'rowid' => $this->rowid,
            'sess' => $this->sess,
            'speaker' => $this->speaker,
            'speech' => $this->speech,
            'topic' => $this->topic,
            'ts' => $this->ts,
            'utterance_id' => $this->utteranceId,
        ];
    }
}
