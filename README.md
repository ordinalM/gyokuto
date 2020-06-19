Gyokuto - a PHP static site generator
=====================================

2020-03-02

## Installation

### Requirements

* [Composer](https://getcomposer.org). You can install this using [Brew](https://brew.sh) or download as per the instructions on [getcomposer.org](https://getcomposer.org).

### Procedure

1. Clone or download the application.
2. Run `composer install` to download the dependencies.

## Application file structure

Directories in this application:

- `content` (default)
  - Contains source files for your site, which are processed on build. If desired, change this with the `content_dir` configuration option.
- `public_html` (default)
  - Contains final built output when "build" or "watch" commands are used. Change this with the `output_dir` option.
- `templates` (default)
  - Twig templates that are used to
- `config` (optional)
  - YAML files containing configuration options or global variables.
- `src`
- `bin`
- `vendor`
  - Contains files for the packages used by Gyokuto. Should exist if you have run `composer install` which you will need to do.

## Data passed to templates

### current_page

The current page being processes, in page data format with the `content` field.

### pages

A list of all pages on the site in page data format, without `content`.

### config

All configuration settings built into Gyokuto and also set in configuration files. These can include arbitrary custom values.

Configuration files
-------------------

Configuration options can be changed or set by adding them to files in YAML format placed in the `config` directory of the application. These files should have extension `.yml` or `.yaml`. They are read once on startup.

Page data format
----------------

### meta

An array of metadata from the page's markdown file.

### path

The final path of this page, which can be used directly in a template.

Pages which would output with a filename of `index.html` are given the path of their parent directory without this filename e.g. file `/blog/index.html` would have `/blog` as its value for `path`.

### level

An integer indicating the level of the page in the site directory structure. Index pages (as above) are given the level of their parent directory. For example:

```
/index.html      -> level 0
/blog/index.html -> level 0
/blog/post.html  -> level 1

```

### id

A unique string identifying this page.

### content (only in current_page)

Rendered HTML from the page's markdown section. This HTML is run through Twig as a template, with the effect that page markdown can use Twig variables and functions, with the same page data as is passed to the final template. For example:

```
---
some_meta_tag: Hello world!
---
This is the markdown section. But this is a Twig variable: {{ some_meta_tag }}
```

will produce:

```
<p>This is the markdown section. But this is a Twig variable: Hello world!</p>
```

## Special page metadata parameters

### draft

Do not include page in build or process it - ignore it completely.

### output_file

A custom output filename. By default, page `foo.md` will be output as `foo.html` - setting:

```
output_file: bar.html
```

will render it as normal and output as `bar.html`.

Directory must be specified in this option, which allows the file to be moved in the output. If it is not specified the page will go into the root output folder.

Setting this to an empty string will not output a file at all. It may do other processing though, such as including it in indexing.

### output_variable

Output the generated page to a variable, as well as or instead of a file. For example:

```
output_variable: page_header
```

will add the template's generated value to `config.output_variable.page_header`. This will then be accessible by any template processed afterwards.

To avoid outputting a file, use the `output_file: ""` option as well.

### pagination

This value will split a single markdown file into multiple pages. It can be used for pagination or metadata catalogues (e.g. a page for each tag).

If "data" is not "pages", the variable `metadata_index` must include it.

```
pagination:
  data: pages
  per_page: 20
```

```
pagination:
  data: tags
```
