<?php

namespace App\Models\Concerns;

use App\Support\TitleCase;

trait FormatsTitleCaseForDisplay
{
    abstract protected function titleCaseDisplayAttributes(): array;

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        foreach ($this->titleCaseDisplayAttributes() as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = TitleCase::format($attributes[$key]);
            }
        }

        return $attributes;
    }

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (!in_array($key, $this->titleCaseDisplayAttributes(), true)) {
            return $value;
        }

        if (is_string($value)) {
            return TitleCase::format($value);
        }

        return $value;
    }
}
