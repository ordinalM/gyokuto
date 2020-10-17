# Gyokuto - a PHP static site generator

## Installation

```
composer require ordinalm/gyokuto dev-master
```

It is advised to add
```
/.gyokuto/
```
to your `.gitignore`.

## Building a site

```
vendor/ordinalm/gyokuto/bin/build
```

By default, this will look for content in `./content` and output to `./www`.