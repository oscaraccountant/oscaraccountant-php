<?php

declare(strict_types=1);

namespace OscarAccountant;

use OscarAccountant\Generated\Runtime\JsonModel;
use OscarAccountant\Generated\Runtime\Page;
use OscarAccountant\Generated\Runtime\Request;

/**
 * The result of a list method.
 *
 * `foreach` gives every item of every page; `firstPage()` gives one page; `pages()` gives every page. The
 * first request carries the parameters of the caller; each next one the same parameters with `cursor` set
 * to the last `next_cursor`, until it is null. A page is fetched only when its first item is needed.
 *
 * ```php
 * foreach ($oscar->invoices->list($companyId, state: 'issued') as $invoice) {
 *     echo $invoice->number, "\n";
 * }
 * ```
 *
 * @template TPage of JsonModel&Page<TItem>
 * @template TItem
 *
 * @implements \IteratorAggregate<int, TItem>
 */
final class Paginator implements \IteratorAggregate
{
    /**
     * @param \Closure(Request): TPage $fetch
     */
    public function __construct(
        private readonly \Closure $fetch,
        private readonly Request $request,
    ) {}

    /**
     * The first page, with the parameters of the caller.
     *
     * @return TPage
     */
    public function firstPage(): JsonModel
    {
        return ($this->fetch)($this->request);
    }

    /**
     * Every page, one request each, fetched when needed.
     *
     * @return \Generator<int, TPage>
     */
    public function pages(): \Generator
    {
        $request = $this->request;
        while (true) {
            $page = ($this->fetch)($request);
            yield $page;
            $cursor = $page->nextCursor();
            if ($cursor === null || $cursor === '') {
                return;
            }
            $request = $request->withQuery('cursor', $cursor);
        }
    }

    /**
     * Every item of every page.
     *
     * @return \Generator<int, TItem>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($page->pageItems() as $item) {
                yield $item;
            }
        }
    }
}
