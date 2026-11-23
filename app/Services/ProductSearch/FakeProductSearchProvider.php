<?php

namespace App\Services\ProductSearch;

use Illuminate\Support\Str;

/**
 * Sample catalog for development and tests. It never leaves the machine and is not bound outside local/testing.
 */
class FakeProductSearchProvider implements ProductSearchProvider
{
    /**
     * Title, price in pesos and extra search words.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const CATALOG = [
        ['Pijama de franela dos piezas', 280, 'pijamas dormir ropa invierno'],
        ['Pijama de satín con pantalón largo', 349, 'pijamas dormir ropa elegante'],
        ['Conjunto de pijama con estampado de renos', 260, 'pijamas navidad ropa'],
        ['Pijama térmica afelpada', 399, 'pijamas invierno ropa calientita'],
        ['Mameluco de dinosaurio para adulto', 298, 'pijamas disfraz ropa divertido'],
        ['Camisón de algodón manga corta', 199, 'pijamas dormir ropa mujer'],
        ['Pantuflas de borrego', 220, 'pantuflas calzado invierno'],
        ['Pantuflas de peluche con forma de animal', 189, 'pantuflas calzado divertido'],
        ['Pantuflas con suela antiderrapante', 159, 'pantuflas calzado casa'],
        ['Antifaz de seda para dormir', 129, 'antifaz dormir descanso'],
        ['Antifaz de gel frío para ojos', 99, 'antifaz descanso belleza'],
        ['Bata de baño de felpa', 459, 'bata baño ropa spa'],
        ['Cobija de franela con mangas', 379, 'cobija sofá invierno'],
        ['Calcetines navideños, paquete de 5 pares', 149, 'calcetines navidad ropa'],
        ['Calcetines térmicos de lana', 119, 'calcetines invierno ropa'],
        ['Taza de cerámica con diseño artesanal', 129, 'taza cocina café'],
        ['Termo de acero inoxidable 500 ml', 249, 'termo bebida café'],
        ['Vela aromática de vainilla', 169, 'vela aroma decoración'],
        ['Set de té con tetera y dos tazas', 389, 'té cocina taza'],
        ['Libro de recetas de repostería', 299, 'libro cocina lectura'],
        ['Novela de misterio en pasta blanda', 219, 'libro lectura'],
        ['Libro de colorear para adultos', 139, 'libro dibujo relax'],
        ['Juego de mesa para toda la familia', 449, 'juego mesa familia diversión'],
        ['Rompecabezas de 1000 piezas', 259, 'juego rompecabezas relax'],
        ['Audífonos inalámbricos con estuche', 599, 'audífonos música tecnología'],
        ['Bocina bluetooth portátil', 499, 'bocina música tecnología'],
        ['Cargador portátil de 10000 mAh', 298, 'cargador batería tecnología'],
        ['Playera de algodón con estampado', 179, 'playera ropa camiseta'],
        ['Sudadera con capucha', 389, 'sudadera ropa invierno'],
        ['Gorro y bufanda de lana', 229, 'gorro bufanda ropa invierno'],
        ['Kit de cuidado facial', 349, 'belleza cuidado piel regalo'],
        ['Set de jabones artesanales', 189, 'jabón belleza spa regalo'],
        ['Chocolates surtidos en caja', 199, 'chocolate dulces comida regalo'],
        ['Canasta de galletas decoradas', 259, 'galletas dulces comida regalo'],
        ['Planta suculenta en maceta', 119, 'planta decoración jardín'],
        ['Lámpara de escritorio con luz cálida', 329, 'lámpara luz decoración'],
        ['Organizador de escritorio de madera', 279, 'organizador oficina escritorio'],
        ['Cuaderno de pasta dura con marcador', 99, 'cuaderno papelería oficina'],
        ['Mochila ligera para laptop', 549, 'mochila laptop viaje'],
    ];

    public function search(string $query, ?int $maxPrice, int $limit, int $page = 1): array
    {
        $terms = array_filter(explode(' ', self::normalize($query)));

        $matches = [];
        foreach (self::CATALOG as [$title, $price, $keywords]) {
            if ($maxPrice !== null && $price > $maxPrice) {
                continue;
            }

            $haystack = self::normalize($title.' '.$keywords);
            $inTitle = 0;
            foreach ($terms as $term) {
                if (! str_contains($haystack, $term)) {
                    continue 2;
                }
                $inTitle += (int) str_contains(self::normalize($title), $term);
            }

            $matches[] = ['inTitle' => $inTitle, 'price' => $price, 'product' => new Product(
                title: $title,
                price: $price,
                imageUrl: null,
                url: 'https://tienda.example/producto/'.Str::slug($title),
                store: 'Tienda de ejemplo',
            )];
        }

        usort($matches, fn (array $left, array $right): int => [$right['inTitle'], $left['price']] <=> [$left['inTitle'], $right['price']]);

        return array_map(fn (array $match): Product => $match['product'], array_slice($matches, ($page - 1) * $limit, $limit));
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }
}
