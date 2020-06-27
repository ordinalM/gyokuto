<?php

/**
 * Generates random blog posts.
 */

$use_images = true;

$count = (int)$argv[1];
if ($count <= 0) {
    echo "First arg is number of posts, 2nd arg is directory\n";
    exit(0);
}

$dir = realpath($argv[2]);
if (empty($dir)) {
    echo "Error: could not find directory from dir argument\n";
    var_dump($argv);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

$faker = Faker\Factory::create();

$all_tags = $faker->words(20);

printf("Generating %d markdown files in %s\n", $count, $dir);

while ($count-- > 0) {
    $title = implode(' ', $faker->words(rand(1, 6)));
    $description = $faker->paragraph();
    $body = implode("\n\n", $faker->paragraphs(rand(5, 15)));
    if ($use_images) {
        $body = preg_split('/[\n\r]+/', $body);
        $images = rand(0, 5);
        while ($images-- > 0) {
            $body[] = sprintf(
                '![%s](%s)',
                $faker->sentence(),
                $faker->imageUrl
            );
            shuffle($body);
        }
        $body = implode("\n\n", $body);
    }
    $possible_tags = $all_tags;
    shuffle($possible_tags);
    $tags = array_slice($possible_tags, 0, rand(2, 5));
    $tag_string = implode(', ', $tags);
    $timestamp = time() - rand(0, 31536000 * 5);
    $date = date('c', $timestamp);
    if ($use_images) {
        $hero_image = 'hero_image: ' . $faker->imageUrl(800, 533);
    }
    $md = <<<MD
---
title: $title
template: post.twig
tags: [$tag_string]
date: $date
description: $description
$hero_image
---
$body

MD;
    $filename =
    sprintf(
        '%s/%s/%s.md',
        $dir,
        $tags[0],
        //       trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-')
        md5(uniqid(true))
    )
    ;
    if (!is_dir(dirname($filename))) {
        mkdir(dirname($filename), 0775, true);
    }
    file_put_contents($filename, $md);
    echo "Generated $filename, $count left\n";
}
