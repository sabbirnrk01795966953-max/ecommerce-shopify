<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use App\Services\SlugService;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $query = Subcategory::query()->with('category');

        if ($search !== '') {
            $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($terms as $term) {
                $like = '%'.$term.'%';

                $query->where(function ($q) use ($like) {
                    $q->where('name_bn', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                        ->orWhereHas('category', function ($categoryQuery) use ($like) {
                            $categoryQuery->where('name_bn', 'like', $like)
                                ->orWhere('name_en', 'like', $like)
                                ->orWhere('slug', 'like', $like);
                        });
                });
            }
        }

        $subcategories = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.subcategories.index', compact('subcategories'));
    }

    public function create(){ return view('admin.subcategories.form',['subcategory'=>new Subcategory,'categories'=>Category::orderBy('sort_order')->get()]); }
    public function store(Request $r){ $data=$this->data($r); $data['slug']=SlugService::unique(SlugService::normalizeUnicodeOrGenerate($data['slug']??null,$data['name_en']?:$data['name_bn'],'subcategory'), Subcategory::class); if($r->hasFile('image'))$data['image_path']=$r->file('image')->store('subcategories','public'); Subcategory::create($data); return redirect()->route('admin.subcategories.index')->with('success','Subcategory created.'); }
    public function edit(Subcategory $subcategory){ return view('admin.subcategories.form',['subcategory'=>$subcategory,'categories'=>Category::orderBy('sort_order')->get()]); }
    public function update(Request $r,Subcategory $subcategory){ $data=$this->data($r,$subcategory); $data['slug']=SlugService::unique(SlugService::normalizeUnicodeOrGenerate($data['slug']??null,$data['name_en']?:$data['name_bn'],'subcategory'), Subcategory::class, $subcategory->id); if($r->hasFile('image'))$data['image_path']=$r->file('image')->store('subcategories','public'); $subcategory->update($data); return redirect()->route('admin.subcategories.index')->with('success','Subcategory updated.'); }
    public function destroy(Subcategory $subcategory){ $subcategory->delete(); return back()->with('success','Subcategory deleted.'); }
    private function data(Request $r,?Subcategory $s=null):array{return $r->validate(['category_id'=>'required|exists:categories,id','name_bn'=>'required|string|max:160','name_en'=>'nullable|string|max:160','slug'=>['nullable','string','max:500'],'image'=>'nullable|image|max:4096','sort_order'=>'nullable|integer|min:0','is_active'=>'nullable|boolean'])+['is_active'=>$r->boolean('is_active'),'sort_order'=>(int)$r->input('sort_order',0)];}
}
