# Gyokuto - a PHP static site generator

## Overview

Gyokuto uses PHP and Twig to render local Markdown content to produce a static site. It can be used as is, or combined with other PHP modules.

## Installation

### Composer (recommended)

In your project, install the module with:
```
composer require ordinalm/gyokuto dev-master
```

It is advised to add
```
/.gyokuto/
```
to your `.gitignore`.


```
vendor/ordinalm/gyokuto/bin/build
```

By default, this will look for content in `./content` and output to `./www`.