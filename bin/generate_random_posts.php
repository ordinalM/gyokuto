<?php

/**
 * Generates random blog posts.
 */

$use_images = false;
$use_internet = false;

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

$word_count = 1000;
$words = explode(
    ' ',
    preg_replace(
        '/[^a-z0-9 ]+/',
        '',
        strtolower(
            file_get_contents(
                'http://www.randomtext.me/download/txt/gibberish/p-1/' . $word_count
            )
        )
    )
);

$all_tags = explode(' ', random_words(20));

printf("Generating %d markdown files in %s\n", $count, $dir);

while ($count-- > 0) {
//   $title = trim(str_replace('.', '', file_get_contents('http://www.randomtext.me/download/txt/gibberish/p-1/1-4')));
    $title = random_words(rand(2, 6));
//   $body = trim(file_get_contents('http://www.randomtext.me/download/txt/gibberish/p-' . rand(4, 10) . '/20-60'));
    $description = random_paragraphs(1);
    $body = random_paragraphs(rand(5, 15));
    if ($use_images) {
        $body = preg_split('/[\n\r]+/', $body);
        $images = rand(0, 5);
        while ($images-- > 0) {
            $body[] = '<p>' . random_image_tag() . '</p>';
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
        $hero_image = 'hero_image: data:image/jpeg;base64,' . random_image_base64(800, 533);
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

function random_image_base64($w = 300, $h = 200)
{
    $f = file_get_contents("https://picsum.photos/$w/$h");
    return base64_encode($f);
}

function random_image_tag($w = 300, $h = 200)
{
    return sprintf('<img src="data:image/jpeg;base64,%s" />', htmlentities(random_image_base64($w, $h)));
}

function random_words($n)
{
    global $words, $word_count;
    $this_words = [];
    while ($n-- > 0) {
        $this_words[] = $words[rand(0, $word_count - 1)];
    }
    return implode(' ', $this_words);
}

function random_paragraphs($n, $min = 20, $max = 100)
{
//  trim(file_get_contents('http://www.randomtext.me/download/txt/gibberish/p-1/10-20'));}
    global $words, $word_count;
    $this_paras = [];
    while ($n-- > 0) {
        $this_paras[] = random_words(rand($min, $max));
    }
    return implode("\n\n", $this_paras);
}
