import {
    LayoutGridIcon,
    ListIcon,
    SearchIcon,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'

export type ProductViewMode =
    | 'cards'
    | 'table'

type ProductsToolbarProps = {
    search: string
    viewMode: ProductViewMode
    onSearchChange: (value: string) => void
    onViewModeChange: (value: ProductViewMode) => void
}

export function ProductsToolbar({
    search,
    viewMode,
    onSearchChange,
    onViewModeChange,
}: ProductsToolbarProps) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative w-full max-w-sm">
                <SearchIcon className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

                <Input
                    value={search}
                    onChange={(event) =>
                        onSearchChange(
                            event.target.value,
                        )
                    }
                    placeholder="Buscar producto..."
                    className="h-9 pl-9"
                />
            </div>

            <div className="flex w-fit items-center rounded-lg border bg-background p-1">
                <Button
                    type="button"
                    size="sm"
                    variant={
                        viewMode === 'cards'
                            ? 'secondary'
                            : 'ghost'
                    }
                    className="h-7 gap-1.5 px-2.5"
                    aria-label="Ver como tarjetas"
                    title="Ver como tarjetas"
                    onClick={() =>
                        onViewModeChange('cards')
                    }
                >
                    <LayoutGridIcon className="size-4" />

                    <span className="hidden sm:inline">
                        Cards
                    </span>
                </Button>

                <Button
                    type="button"
                    size="sm"
                    variant={
                        viewMode === 'table'
                            ? 'secondary'
                            : 'ghost'
                    }
                    className="h-7 gap-1.5 px-2.5"
                    aria-label="Ver como tabla"
                    title="Ver como tabla"
                    onClick={() =>
                        onViewModeChange('table')
                    }
                >
                    <ListIcon className="size-4" />

                    <span className="hidden sm:inline">
                        Tabla
                    </span>
                </Button>
            </div>
        </div>
    )
}
