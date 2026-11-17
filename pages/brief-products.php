<?php
use Design\Guard;
use Design\Products;
use Design\View;

Guard::customer();
View::render('layout', ['title' => 'Новий бриф', 'active' => 'brief', 'body' => View::fetch('brief-products', ['products' => Products::visible()])]);
