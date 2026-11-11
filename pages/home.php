<?php
use Design\Products;
use Design\View;

View::render('layout', ['title' => 'Дизайн для друку', 'body' => View::fetch('home', ['products' => Products::visible()])]);
