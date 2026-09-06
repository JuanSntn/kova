import { Skeleton } from '@/components/ui/skeleton'

export function ProductsPageSkeleton() {
    return (
        <div className="space-y-5">
            <div className="flex justify-between">
                <div className="space-y-2">
                    <Skeleton className="h-6 w-32" />
                    <Skeleton className="h-4 w-64" />
                </div>

                <Skeleton className="h-8 w-28" />
            </div>

            <div className="flex gap-2 overflow-hidden">
                {Array.from({
                    length: 6,
                }).map(
                    (_, index) => (
                        <Skeleton
                            key={index}
                            className="h-8 w-24 shrink-0"
                        />
                    ),
                )}
            </div>

            <Skeleton className="h-9 w-full max-w-sm" />

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                {Array.from({
                    length: 12,
                }).map(
                    (_, index) => (
                        <div
                            key={index}
                            className="overflow-hidden rounded-xl border"
                        >
                            <Skeleton className="aspect-[4/3] w-full rounded-none" />

                            <div className="space-y-2 p-3">
                                <Skeleton className="h-4 w-3/4" />
                                <Skeleton className="h-3 w-1/2" />

                                <div className="flex items-center justify-between pt-2">
                                    <Skeleton className="h-4 w-16" />
                                    <Skeleton className="size-2.5 rounded-full" />
                                </div>
                            </div>
                        </div>
                    ),
                )}
            </div>
        </div>
    )
}