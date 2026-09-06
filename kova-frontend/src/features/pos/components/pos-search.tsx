import {
    SearchIcon,
    XIcon,
} from 'lucide-react'

import {
    Button,
} from '@/components/ui/button'
import {
    Input,
} from '@/components/ui/input'

type PosSearchProps = {
    value: string

    onChange: (
        value: string
    ) => void
}

export function PosSearch({
    value,
    onChange,
}: PosSearchProps) {
    return (
        <div className="relative w-full">
            <SearchIcon className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />

            <Input
                value={
                    value
                }
                placeholder="Buscar producto..."
                className="h-10 pl-9 pr-10"
                onChange={(
                    event,
                ) =>
                    onChange(
                        event.target.value,
                    )
                }
            />

            {value && (
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="absolute right-1 top-1/2 size-8 -translate-y-1/2"
                    aria-label="Limpiar búsqueda"
                    onClick={() =>
                        onChange('')
                    }
                >
                    <XIcon className="size-4" />
                </Button>
            )}
        </div>
    )
}