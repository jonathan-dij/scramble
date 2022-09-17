<?php

namespace Dedoc\Scramble\Support\OperationExtensions\RulesExtractor;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RulesToParameters
{
    private array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function handle()
    {
        return collect($this->rules)
            ->map(fn ($rules, $name) => (new RulesToParameter($name, $rules))->generate())
            ->pipe(\Closure::fromCallable([$this, 'handleNested']))
            ->values()
            ->all();
    }

    private function handleNested(Collection $parameters)
    {
        [$nested, $parameters] = $parameters->partition(fn ($_, $key) => Str::contains($key, '.'));

        $nestedParentsKeys = $nested->keys()->map(fn ($key) => explode('.', $key)[0]);

        [$nestedParents, $parameters] = $parameters->partition(fn ($_, $key) => $nestedParentsKeys->contains($key));

        /** @var Collection $nested */
        $nested = $nested->merge($nestedParents);

        $nested = $nested
            ->groupBy(fn ($_, $key) => explode('.', $key)[0])
            ->map(function (Collection $params, $groupName) {
                $params = $params->keyBy('name');

                $baseParam = $params->get(
                    $groupName,
                    Parameter::make($groupName, $params->first()->in)
                        ->setSchema(Schema::fromType(
                            $params->keys()->contains(fn ($k) => Str::contains($k, "$groupName.*"))
                                ? new ArrayType
                                : new ObjectType
                        ))
                );

                $baseType = $baseParam->schema->type;

                $params->offsetUnset($groupName);

                foreach ($params as $param) {
                    $this->setDeepType($baseType, $param->name, $param->schema->type);
                }

                return $baseParam;
            });

        return $parameters
            ->merge($nested);
    }

    private function setDeepType(Type $base, string $key, Type $typeToSet)
    {
        $containingType = $this->getOrCreateDeepTypeContainer(
            $base,
            collect(explode('.', $key))->splice(1)->values()->all(),
        );
        if (! $containingType) {
            return;
        }

        $isSettingArrayItems = ($settingKey = collect(explode('.', $key))->last()) === '*';

        if ($isSettingArrayItems && $containingType instanceof ArrayType) {
            $containingType->items = $typeToSet;

            return;
        }

        if (! $isSettingArrayItems && $containingType instanceof ObjectType) {
            $containingType
                ->addProperty($settingKey, $typeToSet)
                ->addRequired($typeToSet->getAttribute('required') ? [$settingKey] : []);
        }
    }

    private function getOrCreateDeepTypeContainer(Type &$base, array $path)
    {
        $key = $path[0];

        if (count($path) === 1) {
            return $base;
        }

        if ($key === '*') {
            if (! $base instanceof ArrayType) {
                $base = new ArrayType;
            }

            $next = $path[1];
            if ($next === '*') {
                if (! $base->items instanceof ArrayType) {
                    $base->items = new ArrayType;
                }
            } else {
                if (! $base->items instanceof ObjectType) {
                    $base->items = new ObjectType;
                }
            }

            return $this->getOrCreateDeepTypeContainer(
                $base->items,
                collect($path)->splice(1)->values()->all(),
            );
        } else {
            if (! $base instanceof ObjectType) {
                $base = new ObjectType;
            }

            $next = $path[1];

            if (! $base->hasProperty($key)) {
                $base = $base->addProperty(
                    $key,
                    $next === '*' ? new ArrayType : new ObjectType,
                );
            }

            return $this->getOrCreateDeepTypeContainer(
                $base->properties[$key],
                collect($path)->splice(1)->values()->all(),
            );
        }
    }
}
