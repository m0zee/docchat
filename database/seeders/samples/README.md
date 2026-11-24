# Sample document

`harbor-point-library-handbook.pdf` is a six-page member handbook for a **fictional** library, written for DocChat and dedicated to the public domain under [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/). Anyone may copy, change and use it for any purpose without asking.

Why a fictional document instead of a classic public-domain text: a language model has already read the US Constitution or a Gutenberg novel, so it could answer eval questions from memory and hide retrieval mistakes. Every fact in this handbook (fees, hours, limits) exists only in this file, so a correct answer has to come from retrieval, and each fact sits on a known page for the page-hit metric.

Rebuild the PDF with `php database/seeders/samples/generate.php`. Load it for the demo user with `php artisan db:seed --class=SampleDocumentSeeder`. The questions in `evals/sample.json` are written against this file.
