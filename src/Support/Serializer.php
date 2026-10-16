<?php declare(strict_types=1);

namespace Flora\Support;

use Closure;
use DateTimeInterface;
use JsonException;
use ReflectionClass;
use ReflectionProperty;
use TypeError;
use Flora\Exception\InvalidArgument;
use Flora\Expr\{Expr, Lambda};
use Flora\Value\{CollectionRef, Date, FunctionRef, IndexRef, Query, Ref};

/**
 * Expressions written as JSON and read back, to store the bodies of functions. An expression is an object naming its
 * class, with its properties:
 *
 *     {"@expr": "Arithmetic", "operator": "+", "values": [{"@expr": "Variable", "name": "x"}, 1]}
 *
 * Refs, instants, dates and queries are tagged as in documents ({"@ref": ...}, {"@ts": ...}, {"@date": ...}),
 * collection, index and function refs as {"@collection": "users"}, {"@index": ...}, {"@function": ...}, and an object
 * with a field starting with "@" is wrapped in {"@object": ...}. Values made while a query runs, such as sets, pages or lambdas once evaluated, have
 * no JSON: an expression holding one cannot be stored.
 *
 * @internal
 */
final class Serializer
{

    public static function serialize(mixed $value): string
    {
        return Json::raw(self::write($value));
    }

    public static function unserialize(string $json): mixed
    {
        try {
            return self::read(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException | TypeError $e) {
            throw new InvalidArgument('A stored expression does not fit the expressions of this version of Flora.', 0, $e);
        }
    }

    private static function write(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => is_finite($value) ? $value : throw new InvalidArgument('A stored expression cannot hold an infinite number.'),
            is_array($value) && array_is_list($value) => array_map(self::write(...), $value),
            is_array($value) => self::object(array_map(self::write(...), $value)),
            $value instanceof Expr => self::expression($value),
            $value instanceof Ref => ['@ref' => ['collection' => $value->collection->name, 'id' => $value->id]],
            $value instanceof CollectionRef => ['@collection' => $value->name],
            $value instanceof IndexRef => ['@index' => $value->name],
            $value instanceof FunctionRef => ['@function' => $value->name],
            $value instanceof DateTimeInterface => ['@ts' => Times::format($value)],
            $value instanceof Date => ['@date' => $value->value],
            $value instanceof Query => ['@query' => self::expression($value->lambda)],
            default => throw new InvalidArgument(sprintf(
                'A stored expression cannot hold %s: it is made while a query runs. Build it inside the function instead.',
                get_debug_type($value),
            )),
        };
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return array<array-key, mixed>
     */
    private static function object(array $fields): array
    {
        $tagged = array_any(array_keys($fields), static fn (int|string $field): bool => str_starts_with((string) $field, '@'));

        return $tagged ? ['@object' => $fields] : $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private static function expression(Expr $expr): array
    {
        $written = ['@expr' => new ReflectionClass($expr)->getShortName()];
        foreach (self::properties(new ReflectionClass($expr)) as $property) {
            $written[$property->getName()] = self::write($property->getValue($expr));
        }

        return $written;
    }

    private static function read(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['@expr'])) {
            return self::build($value);
        }

        if (count($value) === 1) {
            $tag = array_key_first($value);
            $content = $value[$tag];

            $name = static fn (mixed $name): string => is_string($name) ? $name : throw self::corrupt();
            $read = match ($tag) {
                '@object' => is_array($content) ? array_map(self::read(...), $content) : throw self::corrupt(),
                '@ref' => is_array($content) ? new Ref(new CollectionRef($name($content['collection'] ?? null)), $name($content['id'] ?? null)) : throw self::corrupt(),
                '@collection' => new CollectionRef($name($content)),
                '@index' => new IndexRef($name($content)),
                '@function' => new FunctionRef($name($content)),
                '@ts' => Times::parse($name($content)),
                '@date' => new Date($name($content)),
                '@query' => new Query(($lambda = self::read($content)) instanceof Lambda ? $lambda : throw self::corrupt()),
                default => false,
            };
            if ($read !== false) {
                return $read;
            }
        }

        return array_map(self::read(...), $value);
    }

    /**
     * Builds an expression from its properties, without its constructor: they are what it made of its arguments.
     *
     * @param array<array-key, mixed> $written
     */
    private static function build(array $written): Expr
    {
        $name = $written['@expr'];
        if (!is_string($name) || !preg_match('/^[A-Z][A-Za-z]*$/', $name)) {
            throw self::corrupt();
        }

        $class = 'Flora\\Expr\\' . $name;
        if (!class_exists($class) || !is_subclass_of($class, Expr::class)) {
            throw self::corrupt();
        }

        $reflection = new ReflectionClass($class);
        $properties = self::properties($reflection);
        unset($written['@expr']);
        if ($reflection->isAbstract() || array_keys($written) != array_map(static fn (ReflectionProperty $property): string => $property->getName(), $properties)) {
            throw self::corrupt();
        }

        /** @var Expr $expr */
        $expr = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $property) {
            // Readonly properties are set from the class declaring them.
            $value = self::read($written[$property->getName()]);
            Closure::bind(static function (object $expr, string $name, mixed $value): void {
                $expr->{$name} = $value;
            }, null, $property->getDeclaringClass()->getName())($expr, $property->getName(), $value);
        }

        return $expr;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @return list<ReflectionProperty>
     */
    private static function properties(ReflectionClass $class): array
    {
        $properties = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if (!$property->isStatic() && $property->getDeclaringClass()->getName() === $current->getName()) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }

    private static function corrupt(): InvalidArgument
    {
        return new InvalidArgument('A stored expression does not fit the expressions of this version of Flora.');
    }
}
